<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Lib;

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Exception\InvalidRequestError;
use Manticoresearch\Buddy\Exception\ManticoreHTTPClientError;

use Manticoresearch\Buddy\Exception\ManticoreResponseError;
use Manticoresearch\Buddy\Exception\ParserLoadError;
use Manticoresearch\Buddy\Exception\QueryParserError;
use Manticoresearch\Buddy\Exception\SocketError;
use RuntimeException;
use Socket;

final class MockManticoreServer {

	const CREATE_RESPONSE_FAIL	= '{"error":"sphinxql: syntax error, unexpected IDENT, expecting '
		. 'CLUSTER or FUNCTION or PLUGIN or TABLE near \'tablee test(col1 text)\'"}';
	const CREATE_RESPONSE_OK = '[{"total":0,"error":"","warning":""}]';
	const SQL_INSERT_RESPONSE_FAIL = '{"error":"index \'test\' absent, or does not support INSERT"}';
	const JSON_INSERT_RESPONSE_FAIL = '{"error":{"type":"index \'test\' absent, or does not support INSERT"'
		. ',"index":"test"},"status":500}';
	const SQL_INSERT_RESPONSE_OK = '[{"total":1,"error":"","warning":""}]';
	const JSON_INSERT_RESPONSE_OK = '{"_index": "test","_id": 1,"created": true,"result": "created","status": 201}';
	const SHOW_QUERIES_RESPONSE_FAIL = '';
	const SHOW_QUERIES_RESPONSE_OK = "[{\n"
		. '"columns":[{"id":{"type":"long long"}},{"proto":{"type":"string"}},{"state":{"type":"string"}},'
		. '{"host":{"type":"string"}},{"connid":{"type":"long long"}},{"killed":{"type":"string"}},'
		. '{"last cmd":{"type":"string"}}],'
		. "\n"
		. '"data":['
		. '{"id":1,"proto":"http","state":"query","host":"127.0.0.1:584","connid":19,"killed":"0","last cmd":"select"}'
		. "\n],\n"
		. '"total":1,'
		. "\n"
		. '"error":"",'
		. "\n"
		. '"warning":""'
		. "\n}]";

	/**
	 * @var Socket|false $socket
	 */
	private $socket;

	/**
	 * @var Socket|false $conn
	 */
	private $conn;

	/**
	 * @var ?int $parentPid
	 */
	private $parentPid = null;

	/**
	 * @var string $reqEndpoint
	 */
	private string $reqEndpoint;

	/**
	 * @param string $addrPort
	 * @param bool $hasErrorResponse
	 * @return void
	 */
	public function __construct(
		private string $addrPort,
		private bool $hasErrorResponse = false,
	) {
	}

	/**
	 * @return void
	 */
	public function start(): void {
		$connInfo = parse_url($this->addrPort);
		if ($connInfo === false
			|| (!array_key_exists('host', $connInfo) || !array_key_exists('port', $connInfo))) {
			exit("<Mock Manticore server terminated: Wrong connection data '{$this->addrPort}' passed");
		}

		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($this->socket === false) {
			throw new SocketError('Cannot create a socket');
		} else {
			if (!socket_bind($this->socket, $connInfo['host'], $connInfo['port']) || !socket_listen($this->socket)) {
				throw new SocketError('Cannot connect to the socket');
			}
			socket_set_nonblock($this->socket);
		}

		echo "<Mock Manticore server started at {$this->addrPort}>";
		$this->checkParentProc();
		while ($this->socket !== false) {
			$this->conn = socket_accept($this->socket);
			if ($this->conn === false) {
				$this->checkParentProc();
				usleep(1000);
			} else {
				socket_set_nonblock($this->conn);
				$req  = $this->readSocketData();
				if (!trim($req)) {
					exit("<Mock Manticore server terminated: Request parse failure: empty request passed>\n");
				}
				preg_match('/(\n|\r)/', $req, $matches, PREG_OFFSET_CAPTURE);
				$reqUrlData = substr($req, 0, (int)$matches[0][1]);
				preg_match('/\s\/(.*?)\s/', $reqUrlData, $matches);
				$this->reqEndpoint = $matches[1];
				preg_match('/(\n\n|\r\n\r\n|\r\r)/', $req, $matches, PREG_OFFSET_CAPTURE);
				$reqBody = substr($req, $matches[0][1] + 4);
				$this->process($reqBody);
			}
		}
	}

	/**
	 * !TODO test implementation for Windows
	 *
	 * Check if the parent process is finished and finish too if true
	 * @return void
	 */
	private function checkParentProc(): void {
		if (strncasecmp(PHP_OS, 'win', 3) === 0) {
			$pid = getmypid();
			$parentPidInfo = shell_exec("wmic process where (processid=$pid) get parentprocessid");
			if (!isset($parentPidInfo) || $parentPidInfo === false) {
				throw new RuntimeException('Cannot check parent state');
			}
			$parentPid = explode("\n", $parentPidInfo);
			$parentPid = (int)$parentPid[1];
		} else {
			$parentPid = posix_getppid();
		}
		if (!isset($this->parentPid)) {
			$this->parentPid = $parentPid;
		} elseif ($parentPid !== $this->parentPid) {
			exit("<Mock Manticore server finished>\n");
		}
	}

	/**
	 * @return string
	 */
	private function readSocketData(): string {
		$data = '';
		$isOk = false;
		$isFinishing = false;
		do {
			if ($this->conn === false) {
				$isOk = false;
			} else {
				$data_packet = socket_read($this->conn, 2048, PHP_BINARY_READ);
				switch ($data_packet) {
					case false:
						if (in_array(
							socket_last_error(),
							[SOCKET_EINPROGRESS, SOCKET_EALREADY, SOCKET_EAGAIN, SOCKET_EWOULDBLOCK]
						)) {
							if ($isFinishing === false) {
								$isFinishing = true;
								usleep(10000);
								$isOk = true;
							} else {
								$isOk = false;
							}
						} else {
							$isOk = false;
						}
						break;
					case '':
						$isOk = false;
						break;
					default:
						$data .= $data_packet;
						$isFinishing = false;
						$isOk = true;
						break;
				}
			}
		} while ($isOk);

		$data = trim($data);
		return $data;
	}

	/**
	 * @param string $request
	 * @return void
	 */
	private function process(string $request): void {
		//echo "\mnt request $request \n";
		if (stripos($request, 'CREATE') === 0) {
			$resp = $this->hasErrorResponse ? self::CREATE_RESPONSE_FAIL : self::CREATE_RESPONSE_OK;
		} elseif (stripos($request, 'INSERT') === 0) {
			$resp = $this->hasErrorResponse ? self::SQL_INSERT_RESPONSE_FAIL : self::SQL_INSERT_RESPONSE_OK;
		} elseif (ManticoreEndpoint::from($this->reqEndpoint) === ManticoreEndpoint::Insert) {
			$resp = $this->hasErrorResponse ? self::JSON_INSERT_RESPONSE_FAIL : self::JSON_INSERT_RESPONSE_OK;
		} elseif (stripos($request, 'SELECT') === 0) {
			$resp = $this->hasErrorResponse ? self::SHOW_QUERIES_RESPONSE_FAIL : self::SHOW_QUERIES_RESPONSE_OK;
		} else {
			$resp = '';
		}
		$this->sendResponse($resp);
	}

	/**
	 * @param string $resp
	 * @return void
	 * @throws ManticoreHTTPClientError
	 * @throws QueryParserError
	 * @throws ParserLoadError
	 * @throws ManticoreResponseError
	 * @throws InvalidRequestError
	 */
	private function sendResponse(string $resp): void {
		if ($this->conn === false) {
			return;
		}
		$respLen = strlen($resp);
		$msg = "HTTP/1.1 200\r\nServer: buddy\r\nContent-Type: application/json; charset=UTF-8\r\n";
		$msg .= "Content-Length: $respLen\r\n\r\n$resp";
		//echo "\n mnt response is $msg";
		socket_write($this->conn, $msg, strlen($msg));
	}
}

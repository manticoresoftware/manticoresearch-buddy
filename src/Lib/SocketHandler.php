<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Interface\CustomErrorInterface;
use Manticoresearch\Buddy\Interface\SocketHandlerInterface;
use \Socket;

class SocketHandler implements SocketHandlerInterface {

	use \Manticoresearch\Buddy\Trait\CustomErrorTrait;

	protected Socket $conn;
	protected Socket $socket;

	/**
	 * @param string $addr
	 * @param int $port
	 * @param CustomErrorInterface $exceptionHandler
	 * @param int $retryCount
	 * @param bool $blockingMode
	 * @return void
	 */
	public function __construct(
		protected string $addr,
		protected int $port,
		protected CustomErrorInterface $exceptionHandler = null,
		protected int $retryCount = 10,
		protected bool $blockingMode = false
	) {
		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($socket === false) {
			$this->error('Cannot create a socket');
		} else {
			$this->socket = $socket;
		}

		$this->bindSocketPort();

		socket_listen($this->socket) || $this->error('Cannot listen to the socket created');
		if ($this->blockingMode === false) {
			socket_set_nonblock($this->socket);
		}

		// outputting connection info for the Manticore server to get it
		echo "started {$this->addr}:{$this->port}\n";
	}

	/**
	 * @return bool
	 */
	public function hasMsg(): bool {
		$conn = socket_accept($this->socket);
		if ($conn !== false) {
			$this->conn = $conn;
			return true;
		}
		return false;
	}

	/**
	 * @return array<mixed>
	 */
	public function readMsg(): array {
		$srcData = $this->readSocketData();
		$lines = preg_split("/\r\n|\n|\r/", $srcData);
		$reqJson = $bodySep = false;
		if ($lines === false) {
			$this->error('HTTP request read error', true);
		} else {
			$bodySep = array_search('', $lines);
			//var_dump ($body_sep);
			//var_dump (count($lines));
			if ($bodySep === false) {
				$this->error('HTTP request without body', true);
			} else {
				$body = trim(implode(array_slice($lines, $bodySep)));
				$reqJson = json_decode($body, true);
			}
		}

		//var_dump ( $reqJson ); // !COMMIT
		if ($reqJson === false) {
			$this->error('JSON data decode error', true);
		}

		return (array)$reqJson;
	}

	/**
	 * @param array{type:string,message:string} $msgData
	 * @return void
	 */
	public function writeMsg(array $msgData): void {
		$body = json_encode($msgData);
		if ($body === false) {
			$this->error('JSON data encode error', true);
		} else {
			$body_len = strlen($body);
			$msg = "HTTP/1.1 200\r\nServer: buddy\r\nContent-Type: application/json; charset=UTF-8\r\n";
			$msg .= "Content-Length: $body_len\r\n\r\n" . $body;
			//var_dump ($msg);

			if (!socket_write($this->conn, $msg, strlen($msg))) {
				$this->error('Socket data write error', true);
			}
		}
	}

	/**
	 * @return void
	 */
	protected function bindSocketPort(): void {
		if ($this->port > 0) {
			if (socket_bind($this->socket, $this->addr, $this->port) === false
				|| socket_getsockname($this->socket, $this->addr, $this->port) === false) {
				$this->error("Cannot bind to a defined addr:port {$this->addr}:{$this->port}");
			}
		} else {
			//trying to bind to any port available on the host machine
			while ($this->port === 0 && $this->retryCount > 0) {
				socket_bind($this->socket, $this->addr, $this->port);
				socket_getsockname($this->socket, $this->addr, $this->port);
				usleep(1000);
				$this->retryCount--;
			}
			if ($this->port === 0) {
				$this->error("Cannot find a port available at addr {$this->addr}");
			}
		}
	}

	/**
	 * @return string
	 */
	protected function readSocketData(): string {
		$data = '';
		$ok = false;
		do {
			switch ($data_packet = socket_read($this->conn, 2048, PHP_BINARY_READ)) {
				case false:
					$this->error('Socket data read error');
					break;
				case '':
					$ok = false;
					break;
				default:
					$data .= trim($data_packet);
					$ok = true;
					break;
			}
			//var_dump ( $data_packet );
		} while ($ok);

		//var_dump ( $data );

		return $data;
	}

}

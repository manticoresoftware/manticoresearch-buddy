<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Exception\SocketError;
use Manticoresearch\Buddy\Interface\SocketHandlerInterface;
use Socket;
use Throwable;

class SocketHandler implements SocketHandlerInterface {

	protected Socket $conn;
	protected Socket $socket;

	/**
	 * @param string $addr
	 * @param int $port
	 * @param int $retryCount
	 * @param bool $blockingMode
	 * @return void
	 */
	public function __construct(
		public string $addr,
		public int $port,
		protected int $retryCount = 10,
		protected bool $blockingMode = false
	) {
		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($socket === false) {
			throw new SocketError('Cannot create a socket');
		} else {
			$this->socket = $socket;
		}

		$this->bindSocketPort();

		socket_listen($this->socket) || throw new SocketError('Cannot listen to the socket created');
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
			if ($this->blockingMode === false) {
				echo 'non block' . "\n";
				socket_set_nonblock($this->conn);
			}
			return true;
		}
		return false;
	}

	/**
	 * @return array{type:string,message:string,request_type:string}|false
	 */
	public function readMsg(): array|false {
		$srcData = $this->read();
		$lines = preg_split("/\r\n|\n|\r/", $srcData);
		$reqJson = $bodySep = false;
		if ($lines === false) {
			throw new SocketError('HTTP request read error');
		} else {
			$bodySep = array_search('', $lines);
			//var_dump ($body_sep);
			//var_dump (count($lines));
			if ($bodySep === false) {
				throw new SocketError('HTTP request without body');
			}
			$body = trim(implode(array_slice($lines, $bodySep)));
			/** @var array{type:string,message:string,request_type:string}|false */
			$reqJson = json_decode($body, true);
		}

		//var_dump ( $reqJson ); // !COMMIT
		if ($reqJson === false) {
			throw new SocketError('JSON data decode error');
		}

		return $reqJson;
	}

	/**
	 * @param array{type:string,message:string} $msgData
	 * @return void
	 */
	public function writeMsg(array $msgData): void {
		$body = json_encode($msgData);
		if ($body === false) {
			throw new SocketError('JSON data encode error');
		}
		$body_len = strlen($body);
		$msg = "HTTP/1.1 200\r\nServer: buddy\r\nContent-Type: application/json; charset=UTF-8\r\n";
		$msg .= "Content-Length: $body_len\r\n\r\n" . $body;
		$this->write($msg);
	}

	/**
	 * @param string $msg
	 * @return void
	 */
	public function write(string $msg): void {
		if (!socket_write($this->conn, $msg, strlen($msg))) {
			throw new SocketError('Socket data write error');
		}
	}

	/**
	 * @return void
	 */
	protected function bindSocketPort(): void {
		if ($this->port > 0) {
			if (!socket_bind($this->socket, $this->addr, $this->port)
				|| !socket_getsockname($this->socket, $this->addr, $this->port)) {
				throw new SocketError("Cannot bind to the defined addr:port {$this->addr}:{$this->port}");
			}
		} else {
			//trying to bind to any port available on the host machine
			while ($this->port === 0 && $this->retryCount > 0) {
				try {
					socket_bind($this->socket, $this->addr, $this->port);
				} catch (Throwable $e) {
					throw new SocketError("Cannot bind to the random port {$this->port} at addr {$this->addr}");
				}
				socket_getsockname($this->socket, $this->addr, $this->port);
				usleep(1000);
				$this->retryCount--;
			}
			if ($this->port === 0) {
				throw new SocketError("Cannot find a port available at addr {$this->addr}");
			}
		}
	}

	/**
	 * @return string
	 */
	public function read(): string {
		$data = '';
		$isOk = false;
		$isFinishing = false;
		do {
			$dataPacket = socket_read($this->conn, 2048, PHP_BINARY_READ);
			switch ($dataPacket) {
				case false:
					if (in_array(
						socket_last_error(),
						[SOCKET_EINPROGRESS, SOCKET_EALREADY, SOCKET_EAGAIN, SOCKET_EWOULDBLOCK]
					)) {
						if ($isFinishing === false) {
							usleep(10000);
							$isFinishing = true;
							$isOk = true;
						} else {
							$isOk = false;
						}
						break;
					}
					throw new SocketError('Socket data read error');
				case '':
					$isOk = false;
					break;
				default:
					$data .= $dataPacket;
					$isFinishing = false;
					$isOk = true;
					break;
			}
		} while ($isOk);

		$data = trim($data);
		return $data;
	}

}

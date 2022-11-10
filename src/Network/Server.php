<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Network;

use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

final class Server {
	/**
	 * Default config for initializing the socket
	 */
	const SOCKET_CONFIG = [
		'tcp' => [
			'backlog' => 200,
			'so_reuseport' => true,
			'ipv4_v4only' => true,
			// 'local_cert' => 'server.pem',
		],
	];

	/** @var SocketServer */
	protected SocketServer $socket;

	/** @var array<string,callable> */
	protected array $handlers = [];

	/** @var array{server:array<callable,int>,client:array<callable,int>} */
	protected array $ticks = [
		'server' => [],
		'client' => [],
	];

	/** @var string $ip */
	protected string $ip;

	/**
	 * @param string $host
	 * @param int $port
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		protected string $host,
		protected int $port,
		array $config = [],
	) {
		// Find the ip of host provided (in case if we pass localhost or whatever)
		$this->ip = match (false) {
			ip2long($host) => gethostbyname($host),
			default => $host,
		};

		// Create the socket for future use
		$this->socket = new SocketServer(
			"$this->host:$this->port",
			array_replace(static::SOCKET_CONFIG, $config)
		);
	}

	/**
	 * Initialize the server and start to process requests
	 *
	 * @static
	 * @param string $address
	 * @param int $port
	 * @param array<string,mixed> $config
	 * @return static
	 */
	public static function create(string $address, int $port, array $config = []): static {
		return new static($address, $port, $config);
	}

	/**
	 * Add handler to process events
	 *
	 * @param string $event
	 * @param callable $fn
	 * @return static
	 */
	public function addHandler(string $event, callable $fn): static {
		$this->handlers[$event] = $fn;
		return $this;
	}

	/**
	 * Add tick function to run in event loop
	 *
	 * @param callable $fn
	 * @param int $period
	 * @param string $type
	 *  One of server or client, todo: move to enum implementation
	 * @return static
	 */
	public function addTicker(callable $fn, int $period = 5, string $type = 'server'): static {
		// We should enum, but for now string is ok
		assert($type === 'server' || $type === 'client');

		$this->ticks[] = [$fn, $period];
		return $this;
	}

	/**
	 * Finally start the server and accept connections
	 *
	 * @return static
	 */
	public function start(): static {
		echo "Starting server on {$this->ip}:{$this->port}" . PHP_EOL;

		// First add all ticks to run periodically
		foreach ($this->ticks['server'] as [$fn, $period]) {
			Loop::addPeriodicTimer($period, $this->wrapFn($fn));
		}

		// Handle connections and subscribe to all events in handlers
		$this->socket->on(
			'connection', function (ConnectionInterface $connection) {
				echo 'New connection from ' . $connection->getRemoteAddress() . PHP_EOL;

			// First add all ticks to run periodically
				foreach ($this->ticks['client'] as [$fn, $period]) {
					Loop::addPeriodicTimer($period, $this->wrapFn($fn, $connection));
				}

				foreach ($this->handlers as $event => $fn) {
					$connection->on($event, $this->wrapFn($fn, $connection));
				}
			}
		);

		return $this;
	}

	/**
	 * Little helper to help us to wrap event handler into function
	 * that will do responses to current connectsion whe we have it
	 * and also support multiple responses at once
	 *
	 * @param callable $fn
	 * @param ?ConnectionInterface $connection
	 * @return callable
	 */
	protected function wrapFn(callable $fn, ?ConnectionInterface $connection = null): callable {
		return function (...$args) use ($fn, $connection) {
			$result = $fn(...$args);

			if (!$connection) {
				return;
			}

			if (is_a($result, Response::class)) {
				return $connection->write((string)$result);
			}

			if (!is_array($result)) {
				return;
			}

			foreach ($result as $response) {
				if (!is_a($response, Response::class)) {
					continue;
				}
				$connection->write((string)$response);
			}
		};
	}
}

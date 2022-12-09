<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Network;

use Exception;
use React\EventLoop\Loop;
use React\Http\HttpServer;
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

	/** @var array{server:array<array{0:callable,1:int}>,client:array<array{0:callable,1:int}>} */
	protected array $ticks = [
		'server' => [],
		'client' => [],
	];

	/** @var string $ip */
	protected string $ip;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		array $config = [],
	) {
		$this->socket = new SocketServer(
			'127.0.0.1:0',
			array_replace(static::SOCKET_CONFIG, $config)
		);
	}

	/**
	 * Initialize the server and start to process requests
	 *
	 * @static
	 * @param array<string,mixed> $config
	 * @return static
	 */
	public static function create(array $config = []): static {
		return new static($config);
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

		$this->ticks[$type][] = [$fn, $period];
		return $this;
	}

	/**
	 * Finally start the server and accept connections
	 *
	 * @return static
	 */
	public function start(): static {
		buddy_metric('invocation', 1);

		echo 'started ' . str_replace('tcp://', '', (string)$this->socket->getAddress()) . PHP_EOL;
		// First add all ticks to run periodically
		foreach ($this->ticks['server'] as [$fn, $period]) {
			Loop::addPeriodicTimer($period, $this->wrapFn($fn));
		}

		// Handle connections and subscribe to all events in handlers
		// Create the socket for future use
		if (!isset($this->handlers['request'])) {
			throw new Exception('You are missing "request" handler to handle requeests');
		}
		$http = new HttpServer($this->handlers['request']);
		unset($this->handlers['request']);

		$this->socket->on(
			'connection', function (ConnectionInterface $connection) {
				debug('New connection from ' . $connection->getRemoteAddress());

				// First add all ticks to run periodically
				foreach ($this->ticks['client'] as [$fn, $period]) {
					Loop::addPeriodicTimer($period, $this->wrapFn($fn, $connection));
				}

				foreach ($this->handlers as $event => $fn) {
					$connection->on($event, $this->wrapFn($fn, $connection));
				}
			}
		);

		$http->listen($this->socket);
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

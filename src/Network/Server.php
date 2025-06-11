<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify

  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Network;

use Ds\Set;
use Exception;
use Manticoresearch\Buddy\Core\Network\Response;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\ConfigManager;
use Swoole\Http\Server as SwooleServer;
use Swoole\Process;
use Swoole\Timer;
use Throwable;

final class Server {
	const PROCESS_NAME = 'manticoresearch-buddy';

	/** @var SwooleServer */
	protected SwooleServer $socket;

	/** @var array<string,callable> */
	protected array $handlers = [];

	/** @var array<array{0:callable,1:int}> */
	protected array $ticks = [];

	/** @var array<callable> */
	protected array $onstart = [];

	/** @var array<callable> */
	protected array $beforeStart = [];

	/** @var array<callable> */
	protected array $onstop = [];

	/** @var string $bind */
	protected string $bind;

	/** @var int $pid */
	protected int $pid;

	/** @var int $ppid */
	protected int $ppid;

	/** @var Set<int> $workerIds */
	protected Set $workerIds;

	/**
	 * @param array<string,mixed> $config
	 * @return void
	 */
	public function __construct(array $config = []) {
		$this->bind = ConfigManager::get('BIND_HOST', '127.0.0.1');
		$this->socket = new SwooleServer($this->bind, ConfigManager::getInt('BIND_PORT', 0));
		$this->socket->set($config);
		$this->ppid = posix_getppid();

		$this->addTicker(
			function () {
				if (Process::kill($this->ppid, 0)) {
					return;
				}
				$this->stop();
			}, 1
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
	 *  One of server or client, todo: move to enum implementation
	 * @return static
	 */
	public function addTicker(callable $fn, int $period = 5): static {
		$this->ticks[] = [$fn, $period];
		return $this;
	}

	/**
	 * Add function to be called and processed on server start
	 * @param callable $fn
	 * @return static
	 */
	public function onStart(callable $fn): static {
		$this->onstart[] = $fn;
		return $this;
	}

	/**
	 * Add function to be called and processed on server stop
	 * @param callable $fn
	 * @return static
	 */
	public function onStop(callable $fn): static {
		$this->onstop[] = $fn;
		return $this;
	}

	/**
	 * Add function to be called before we starting server
	 * @param  callable $fn
	 * @return static
	 */
	public function beforeStart(callable $fn): static {
		$this->beforeStart[] = $fn;
		return $this;
	}

	/**
	 * Add process to the server loop
	 * @param Process $process
	 * @return static
	 */
	public function addProcess(Process $process): static {
		$this->socket->addProcess($process);
		$this->onStart(
			static function () use ($process) {
				swoole_event_add(
					$process->pipe, function ($pipe) use ($process) {
						try {
							$output = $process->read();
							if (!$output) {
								swoole_event_del($pipe);
								$process->wait();
							}

							if (is_string($output)) {
								echo $output;
							}
						} catch (Throwable) {
						}
					}
				);
			}
		);
		return $this;
	}

	/**
	 * Finally start the server and accept connections
	 *
	 * @return static
	 */
	public function start(): static {
		// This is must be first! Because its important
		$version = Buddy::getVersion();
		$listen = "{$this->bind}:{$this->socket->port}";
		echo "Buddy v{$version} started {$listen}" . PHP_EOL;

		// Handle connections and subscribe to all events in handlers
		// Create the socket for future use
		if (!isset($this->handlers['request'])) {
			throw new Exception('You are missing "request" handler to handle requests');
		}

		foreach ($this->beforeStart as $fn) {
			$fn();
		}

		// Do custom initialization on start
		$this->socket->on(
			'start', function () {
				$name = Buddy::getProcessName(static::PROCESS_NAME);
				swoole_set_process_name($name);
				$this->pid = $this->socket->master_pid;
				pcntl_async_signals(true);
				pcntl_signal(SIGTERM, $this->stop(...));
				pcntl_signal(SIGINT, $this->stop(...));

				// Process first functions to run on start
				foreach ($this->onstart as $fn) {
					$fn();
				}
			}
		);

		// Register shutdown on stop
		$this->socket->on(
			'shutdown', function () {
				// Process first functions to run on start
				foreach ($this->onstop as $fn) {
					$fn();
				}
			}
		);

		$this->socket->on(
			// @phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			'WorkerStart', function (SwooleServer $server, int $workerId) {
				$name = Buddy::getProcessName(static::PROCESS_NAME, 'worker', $workerId);
				swoole_set_process_name($name);
				if ($workerId !== 0) {
					if (!isset($this->workerIds)) {
						$this->workerIds = new Set;
					}

					$this->workerIds->add($workerId);
					return;
				}

				// First add all ticks to run periodically
				foreach ($this->ticks as [$fn, $period]) {
					Timer::tick(
						$period * 1000,
						static function (/*int $timerId*/) use ($fn) {
							go($fn);
						}
					);
				}
			}
		);

		$this->socket->on(
			// @phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.CodeAnalysis.UnusedFunctionParameter.Found
			'ManagerStart', function (SwooleServer $server) {
				swoole_set_process_name(Buddy::getProcessName(static::PROCESS_NAME, 'manager'));
			}
		);

		// Add all other handlers
		foreach ($this->handlers as $event => $fn) {
			$this->socket->on($event, $fn);
		}

		$this->socket->start();
		return $this;
	}

	/**
	 * Run task in worker, we do blocking here, but actually we detect
	 * async mode on the level of our Task
	 * @param string $requestId
	 * @param  string $payload
	 * @return Response
	 */
	public function process(string $requestId, string $payload): Response {
		// @phpstan-ignore-next-line
		return $this->socket->taskwait([$requestId, $payload], 0);
	}

	/**
	 * Stop running server
	 *
	 * @param bool $exit
	 * @return static
	 */
	public function stop($exit = true): static {
		Timer::clearAll();
		$this->socket->stop();
		$this->socket->shutdown();

		if ($exit) {
			// To be sure that all stopped, kill now cuz it should be done till this
			// exec('pgrep -f executor | xargs kill -9');
			exit(0);
		}

		return $this;
	}
}

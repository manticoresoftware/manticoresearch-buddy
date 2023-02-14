<?php declare(strict_types=1);

/*
	Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 2 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Lib\Task;

use Closure;
use Manticoresearch\Buddy\Exception\GenericError;
use RuntimeException;
use parallel\Channel;
use parallel\Future;
use parallel\Runtime;

final class Task {
	// Higher is better for perf just because we monitor looped tasks during transmitting
	const CHANNEL_CAPACITY = 100;

	protected int $id;

	/**
	 * This flag shows that this task is deffered and
	 * we can return response to client asap
	 *
	 * @var bool $isDeferred
	 */
	protected bool $isDeferred = false;

	/**
	 * This is type of task that run in a loop and has auto restart logic
	 *
	 * @var bool $isLooped
	 */
	protected bool $isLooped = false;

	/**
	 * Current task status
	 *
	 * @var TaskStatus $status
	 */
	protected TaskStatus $status;
	protected Future $future;
	protected Runtime $runtime;
	protected Channel $channel;
	protected GenericError $error;
	protected TaskResult $result;

	protected int $channelBufferCount = 0;

	// Extended properties for make things simpler
	protected string $host = '';
	protected string $body = '';

	/**
	 * @param mixed[] $argv
	 * @return void
	 */
	public function __construct(protected array $argv = []) {
		$this->id = (int)(microtime(true) * 10000);
		$this->status = TaskStatus::Pending;
	}

	/**
	 * Destroy it and make sure that runtime is killed and channel closed
	 * @return void
	 */
	public function destroy(): void {
		$this->channel->close();
		$this->runtime->kill();
	}

	/**
	 * Check if this task is deferred
	 * @return bool
	 */
	public function isDeferred(): bool {
		return $this->isDeferred;
	}

	/**
	 * Check if this task is looped task and long running
	 * @return bool
	 */
	public function isLooped(): bool {
		return $this->isLooped;
	}

	/**
	 * Get current task ID
	 *
	 * @return int
	 */
	public function getId(): int {
		return $this->id;
	}

	/**
	 * This method creates new runtime and initialize the task to run in i
	 *
	 * @param Closure $fn
	 * @param mixed[] $argv
	 * @return static
	 * @see static::createInRuntime()
	 */
	public static function create(Closure $fn, array $argv = []): static {
		return static::createInRuntime(static::createRuntime(), $fn, $argv);
	}

	/**
	 * This method creates new runtime and initialize task to run in deffered mode
	 * It accepts same parameters as create method
	 * @param Closure $fn
	 * @param mixed[] $argv
	 * @return static
	 * @see static::create()
	 */
	public static function defer(Closure $fn, array $argv = []): static {
		$self = static::createInRuntime(static::createRuntime(), $fn, $argv);
		$self->isDeferred = true;
		return $self;
	}

	/**
	 * This is main function to create runtime and initialize the task
	 *
	 * @param Runtime $runtime
	 * @param Closure $fn
	 *  The closure should be catch all exception and work properly withou failure
	 *  Otherwise the all loop will be stopped
	 * @param mixed[] $argv
	 * @return static
	 */
	public static function createInRuntime(Runtime $runtime, Closure $fn, array $argv = []): static {
		$task = new static([$fn, $argv]);
		$task->runtime = $runtime;
		return $task;
	}

	/**
	 * Defer in specified runtime
	 *
	 * @param Runtime $runtime
	 * @param Closure $fn
	 *  The closure should be catch all exception and work properly without failure
	 *  Otherwise the all loop will be stopped
	 * @param mixed[] $argv
	 * @return static
	 */
	public static function deferInRuntime(Runtime $runtime, Closure $fn, array $argv = []): static {
		$task = static::createInRuntime($runtime, $fn, $argv);
		$task->isDeferred = true;
		return $task;
	}

	/**
	 * Create specific task that is long running and we need to maintain it on crash
	 *
	 * @param Runtime $runtime
	 * @param Closure $fn
	 *  The closure should be catch all exception and work properly without failure
	 *  Otherwise the all loop will be stopped
	 * @param mixed[] $argv
	 * @return static
	 */
	public static function loopInRuntime(Runtime $runtime, Closure $fn, array $argv = []): static {
		$task = static::createInRuntime($runtime, $fn, $argv);
		$task->isLooped = true;
		// Currently we use channels only for metric threads,
		// Thats why we hardcoded capacity here, and it's totally ok for now
		// Buffered channel does not block on send and blocks only when
		// we reach passed capacity, we use 50 for now, but it's subject to change
		$task->channel = new Channel(static::CHANNEL_CAPACITY);
		// Add channel as first argument for argv in case it's lopped
		array_unshift($task->argv[1], $task->channel); // @phpstan-ignore-line
		return $task;
	}

	/**
	 * Create application runtime with init and autoload injected
	 *
	 * @return Runtime
	 */
	public static function createRuntime(): Runtime {
		return new Runtime(__DIR__. '/../../runtime.php');
	}

	/**
	 * Launch the current task
	 *
	 * @return static
	 */
	public function run(): static {
		$future = $this->runtime->run(
			function (Closure $fn, array $argv): array {
				if (!defined('STDOUT')) {
					define('STDOUT', fopen('php://stdout', 'wb+'));
				}

				if (!defined('STDERR')) {
					define('STDERR', fopen('php://stderr', 'wb+'));
				}

				try {
					return [null, $fn(...$argv)];
				} catch (\Throwable $t) {
					return [[$t::class, $t->getMessage()], null];
				}
			}, $this->argv
		);

		if (!isset($future)) {
			$this->status = TaskStatus::Failed;
			throw new RuntimeException("Failed to run task: {$this->id}");
		}
		$this->future = $future;
		$this->status = TaskStatus::Running;
		return $this;
	}

	/**
	 * Blocking call to wait till the task is finished
	 *
	 * @param bool $exceptionOnError
	 *  If we should throw exception in case of failure
	 * 	or just simply return the current status
	 * 	and give possibility to caller handle it and check
	 *
	 * @return TaskStatus
	 * @throws GenericError
	 */
	public function wait(bool $exceptionOnError = false): TaskStatus {
		$i = 0;
		while ($this->status === TaskStatus::Running) {
			$this->checkStatus();
			usleep(5 + (int)log(++$i));
		}

		if ($exceptionOnError && !$this->isSucceed()) {
			throw $this->getError();
		}

		return $this->status;
	}

	/**
	 * This is internal function to get current state of running future
	 * and update status in state of the current task
	 *
	 * @return static
	 * @throws RuntimeException
	 */
	protected function checkStatus(): static {
		if ($this->isDone()) {
			$this->status = TaskStatus::Finished;

			try {
				/** @var array{0:?array{0:string,1:string}, 1:TaskResult} */
				$value = $this->future->value();
				[$error, $result] = $value;
				if ($error) {
					/** @var array{0:string,1:string} $error */
					[$errorClass, $errorMessage] = $error;
					$e = new GenericError("$errorClass: $errorMessage");
					if ($errorMessage) {
						$e->setResponseError($errorMessage);
					}
					throw $e;
				}

				$this->result = $result;
			} catch (GenericError $error) {
				$this->error = $error;
			}
		}
		return $this;
	}

	/**
	 * Get current status of launched task
	 *
	 * @return TaskStatus
	 */
	public function getStatus(): TaskStatus {
		// First, we need to check status,
		// in case we do not use wait for blocking resolving
		if ($this->status !== TaskStatus::Pending) {
			$this->checkStatus();
		}

		return $this->status;
	}

	/**
	 * Shortcut to check if the task is still running
	 *
	 * @return bool
	 */
	public function isRunning(): bool {
		return $this->getStatus() === TaskStatus::Running;
	}

	/**
	 * @return bool
	 */
	public function isSucceed(): bool {
		return !$this->isRunning() && !isset($this->error);
	}

	/**
	 * Return if task is done (even when crashed)
	 * @return bool
	 */
	public function isDone(): bool {
		return $this->future->done();
	}

	/**
	 * Just getter for current error
	 *
	 * @return GenericError
	 * @throws RuntimeException
	 */
	public function getError(): GenericError {
		if (!isset($this->error)) {
			throw new RuntimeException('There error was not set, you should call isScucceed first.');
		}
		return $this->error;
	}

	/**
	 * Just getter for result of future
	 *
	 * @return TaskResult
	 * @throws RuntimeException
	 */
	public function getResult(): TaskResult {
		if (!isset($this->result)) {
			throw new RuntimeException('There result was not set, you should be sure that isSucceed returned true.');
		}

		return $this->result;
	}

	/**
	 * This method simply send the message to the running function for this task
	 * and also check and rerun the closure in case if its looped
	 *
	 * @param array<mixed> $data
	 * @return static
	 */
	public function transmit(array $data): static {
		$shouldCheck = false;
		++$this->channelBufferCount;
		if ($this->channelBufferCount === static::CHANNEL_CAPACITY) {
			$this->channelBufferCount = 0;
			$shouldCheck = true;
		}
		// This is a bit tricky but to fight with killed/crashed runtimes
		// We check if it's still running here and in case not, restart it
		if ($this->isLooped && $shouldCheck && $this->isDone()) {
			$this->run();
		}

		$this->channel->send($data);
		return $this;
	}

	/**
	 * @param string $host
	 * return static
	 */
	public function setHost(string $host): static {
		$this->host = $host;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getHost(): string {
		return $this->host;
	}

	// Now setter and getter for body property
	/**
   * @param string $body
   * return static
   */
	public function setBody(string $body): static {
		$this->body = $body;
		return $this;
	}

	/**
   * @return string
   */
	public function getBody(): string {
		return $this->body;
	}
}

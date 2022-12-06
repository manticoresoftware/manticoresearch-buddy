<?php declare(strict_types=1);

/*
	Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 2 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Lib;

use Closure;
use Manticoresearch\Buddy\Lib\TaskStatus;
use RuntimeException;
use Throwable;
use parallel\Future;
use parallel\Runtime;

final class Task {
	protected int $id;

	/**
	 * This flag shows that this task is deffered and
	 * we can return response to client asap
	 *
	 * @var bool $isDeferred
	 */
	protected bool $isDeferred = false;
	/**
	 * Current task status
	 *
	 * @var TaskStatus $status
	 */
	protected TaskStatus $status;
	protected Future $future;
	protected Runtime $runtime;
	protected Throwable $error;
	protected mixed $result;

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
	 * Check if this task is deferred
	 * @return bool
	 */
	public function isDeferred(): bool {
		return $this->isDeferred;
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
		$Self = static::createInRuntime(static::createRuntime(), $fn, $argv);
		$Self->isDeferred = true;
		return $Self;
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
	 * Create application runtime with init and autoload injected
	 *
	 * @return Runtime
	 */
	public static function createRuntime(): Runtime {
		return new Runtime(__DIR__. '/../../vendor/autoload.php');
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
	 * @throws Throwable
	 */
	public function wait(bool $exceptionOnError = false): TaskStatus {
		while ($this->status === TaskStatus::Running) {
			$this->checkStatus();
			usleep(500000);
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
		if ($this->future->done()) {
			$this->status = TaskStatus::Finished;

			try {
				/** @var array<mixed> */
				$value = $this->future->value();
				[$error, $result] = $value;
				if ($error) {
					/** @var array{0:string,1:string} $error */
					[$errorClass, $errorMessage] = $error;
					throw new $errorClass($errorMessage); // @phpstan-ignore-line
				}

				$this->result = $result;
			} catch (Throwable $error) {
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
	 * Just getter for current error
	 *
	 * @return Throwable
	 * @throws RuntimeException
	 */
	public function getError(): Throwable {
		if (!isset($this->error)) {
			throw new RuntimeException('There error was not set, you should call isScucceed first.');
		}
		return $this->error;
	}

	/**
	 * Just getter for result of future
	 *
	 * @return mixed
	 * @throws RuntimeException
	 */
	public function getResult(): mixed {
		if (!isset($this->result)) {
			throw new RuntimeException('There result was not set, you should be sure that isSucceed returned true.');
		}

		return $this->result;
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

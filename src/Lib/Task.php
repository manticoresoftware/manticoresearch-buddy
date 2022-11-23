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
	protected string $id;
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

	/**
	 * @param mixed[] $argv
	 * @return void
	 */
	public function __construct(protected array $argv = []) {
		$this->id = uniqid();
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
	 * @return string
	 */
	public function getId(): string {
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
		$autoload_file = __DIR__ . '/../../vendor/autoload.php';
		return static::createInRuntime(new Runtime($autoload_file), $fn, $argv);
	}

	/**
	 * This method creates new runtime and initialize task to run in deffered mode
	 * It accepts same parameters as create method
	 * @param Closure $fn
	 * @param mixed[] $argv
	 * @return static
	 * @see static::create()
	 */
	public static function createDeferred(Closure $fn, array $argv = []): static {
		$autoload_file = __DIR__. '/../../vendor/autoload.php';
		$Self = static::createInRuntime(new Runtime($autoload_file), $fn, $argv);
		$Self->isDeferred = true;
		return $Self;
	}

	/**
	 * This is main function to create runtime and initialize the task
	 *
	 * @param Runtime $runtime
	 * @param Closure $fn
	 * @param mixed[] $argv
	 * @return static
	 */
	public static function createInRuntime(Runtime $runtime, Closure $fn, array $argv = []): static {
		$task = new static([$fn, $argv]);
		$task->runtime = $runtime;
		return $task;
	}

	/**
	 * Launch the current task
	 *
	 * @return static
	 */
	public function run(): static {
		$future = $this->runtime->run(
			function (Closure $fn, array $argv): mixed {
				if (!defined('STDOUT')) {
					define('STDOUT', fopen('php://stdout', 'wb+'));
				}

				if (!defined('STDERR')) {
					define('STDERR', fopen('php://stderr', 'wb+'));
				}

				try {
					return $fn(...$argv);
				} catch (Throwable $e) {
					return $e->getMessage();
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
				$this->result = $this->future->value();
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
}

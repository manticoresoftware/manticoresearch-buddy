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
use function parallel\run;

final class Task {
	protected TaskStatus $status;
	protected Future $future;
	protected Throwable $error;
	protected bool|string $result;

	/**
	 * @param string $id
	 * @param mixed[] $argv
	 * @return void
	 */
	public function __construct(protected string $id, protected array $argv = []) {
		$this->status = TaskStatus::Pending;
	}

	/**
	 * @param string $task
	 * @param Closure $fn
	 * 	The closure should return bool or string as value due to
	 *  some limitations that we have when using the parallel extension
	 * @param mixed[] $argv
	 * @return static
	 */
	public static function create(string $task, Closure $fn, array $argv = []): static {
		return new static($task, [$fn, serialize($argv)]);
	}

	/**
	 * Launch the current task
	 *
	 * @return static
	 */
	public function run(): static {
		$future = run(
			function (Closure $fn, string $argv): bool|string {
				define('STDOUT', fopen('/dev/stdout', 'wb+'));
				define('STDERR', fopen('/dev/stderr', 'wb+'));

				include_once __DIR__ . '/../../vendor/autoload.php';
				try {
					/** @var mixed[] $args */
					$args = unserialize($argv);
					$fn(...$args);
				} catch (Throwable $e) {
					return $e->getMessage();
				}
				return true;
			}, $this->argv
		);

		if ($future === null) {
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
				$value = $this->future->value();
				if (!is_bool($value) && !is_string($value)) {
					throw new RuntimeException('Incorrect future value returned');
				}
				$this->result = $value;
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
		return $this->status === TaskStatus::Running;
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
	 * @return bool|string
	 * @throws RuntimeException
	 */
	public function getResult(): bool|string {
		if (!isset($this->result)) {
			throw new RuntimeException('There result was not set, you should be sure that isSucceed returned true.');
		}

		return $this->result;
	}
}

<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy;

use Manticoresearch\Buddy\Interface\BuddyResponseInterface;
use Manticoresearch\Buddy\Interface\SocketHandlerInterface;
use Manticoresearch\Buddy\Lib\QueryProcessor;
// @codingStandardsIgnoreStart
use Manticoresearch\Buddy\Lib\Task;
use Manticoresearch\Buddy\Lib\TaskStatus;
// @codingStandardsIgnoreEnd
use Manticoresearch\Buddy\Trait\BuddyResponseTrait;
use \RuntimeException;
use \Throwable;

final class BuddyTestLoop implements BuddyResponseInterface {

	use BuddyResponseTrait;

	/**
	 * @var array<Task> $tasksRunning;
	 */
	private array $tasksRunning;

	/**
	 * @param SocketHandlerInterface $socketHandler
	 * @param string $clientPid
	 * @param string $clientPidPath
	 */
	public function __construct(
		private SocketHandlerInterface $socketHandler,
		private string $clientPid,
		private string $clientPidPath
	) {
		$this->tasksRunning = [];
	}

	public function start(): void {
		// listening socket in loop
		while (true) {
			if (!$this->socketHandler->hasMsg()) {
				if (!$this->isClientAlive()) {
					die();
				}
				if (!empty($this->tasksRunning)) {
					$this->checkTasks();
				}
				usleep(1000);
			} else {
				try {
					$req = $this->socketHandler->read();
					if ($req !== false) {
						$executor = QueryProcessor::process($req);
						$this->tasksRunning[] = $executor->run();
					}
				} catch (Throwable $e) {
					$resp = $this->buildResponse(message: '', error: $e->getMessage());
					try {
						$this->socketHandler->write($resp);
					} catch (RuntimeException) {
					}
				}
			}
		}
	}

	/**
	 * Checking if Manticore server is alive
	 *
	 * @return bool
	 */
	private function isClientAlive(): bool {
		$pidFromFile = -1;
		if (file_exists($this->clientPidPath)) {
			$content = file_get_contents($this->clientPidPath);
			if ($content === false) {
				return false;
			}
			$pidFromFile = substr($content, 0, -1);
		}
		return $this->clientPid === $pidFromFile;
	}

	/**
	 * Looking for finished tasks and return their result or error as response to Manticore server
	 *
	 * @return void
	 */
	private function checkTasks(): void {
		$this->tasksRunning = array_filter(
			$this->tasksRunning,
			function ($task) {
				$taskStatus = $task->getStatus();
				if (in_array($taskStatus, [TaskStatus::Pending, TaskStatus::Running])) {
					return true;
				}
				try {
					if ($taskStatus === TaskStatus::Failed) {
						$resp = $this->buildResponse(message: '', error: 'Buddy task failed to start');
					} else {
						if ($task->isSucceed()) {
							$resp = $task->getResult();
						} else {
							$error = $task->getError()->getMessage();
							$resp = $this->buildResponse(message: '', error: $error);
						}
					}
					$this->socketHandler->write((string)$resp);
				} catch (Throwable $e) {
					$resp = $this->buildResponse(message: '', error: $e->getMessage());
					try {
						$this->socketHandler->write($resp);
					} catch (RuntimeException) {
					}
				}
				return false;
			}
		);
	}

}

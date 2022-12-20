<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\BuddyUnitTest\Lib;

use Exception;
use Manticoresearch\Buddy\Exception\BuddyRequestError;
use Manticoresearch\Buddy\Exception\GenericError;
use Manticoresearch\Buddy\Lib\Task;
use Manticoresearch\Buddy\Lib\TaskStatus;
use PHPUnit\Framework\TestCase;
use stdClass;

class TaskTest extends TestCase {
	public function testTaskParallelRunSucceed(): void {
		echo "\nTesting the task parallel run succeed\n";
		$Task = Task::create(
			function (): bool {
				usleep(2000000);
				return true;
			}
		);
		$this->assertEquals(TaskStatus::Pending, $Task->getStatus());
		$Task->run();
		$this->assertEquals(TaskStatus::Running, $Task->getStatus());
		usleep(2500000);
		$this->assertEquals(TaskStatus::Finished, $Task->getStatus());
		$this->assertEquals(true, $Task->isSucceed());
		$this->assertEquals(true, $Task->getResult());
	}

	public function testTaskParallelRunWithArgumentsSucceed(): void {
		echo "\nTesting the task parallel run with arguments succeed\n";
		$arg = new stdClass();
		$arg->name = 'test';
		$arg->value = 123;

		$Task = Task::create(
			function (stdClass $arg): stdClass {
				usleep(2000000);
				return $arg;
			},
			[$arg]
		);

		$this->assertEquals(TaskStatus::Pending, $Task->getStatus());
		$Task->run();
		$this->assertEquals(TaskStatus::Running, $Task->getStatus());
		usleep(2500000);
		$this->assertEquals(TaskStatus::Finished, $Task->getStatus());
		$this->assertEquals(true, $Task->isSucceed());
		$this->assertEquals($arg, $Task->getResult());
	}

	public function testTaskReturnsGenericErrorOnException(): void {
		echo "\nTesting the task's exception converts to generic error\n";
		$errorMessage = 'Here we go';
		$Task = Task::create(
			function () use ($errorMessage): bool {
				throw new Exception($errorMessage);
			}
		);
		$this->assertEquals(TaskStatus::Pending, $Task->getStatus());
		$Task->run();
		$this->assertEquals(TaskStatus::Running, $Task->getStatus());
		usleep(500000);
		$this->assertEquals(TaskStatus::Finished, $Task->getStatus());
		$this->assertEquals(false, $Task->isSucceed());
		$error = $Task->getError();
		$this->assertEquals(true, $error instanceof GenericError);
		$this->assertEquals(Exception::class . ': ' . $errorMessage, $error->getMessage());
		$this->assertEquals($errorMessage, $error->getResponseError());
	}

	public function testTaskReturnsGenericErrorOnCustomException(): void {
		echo "\nTesting the task's custom exception converts to generic error\n";
		$errorMessage = 'Custom error message';
		$Task = Task::create(
			function () use ($errorMessage): bool {
				throw new BuddyRequestError($errorMessage);
			}
		);
		$this->assertEquals(TaskStatus::Pending, $Task->getStatus());
		$Task->run();
		$this->assertEquals(TaskStatus::Running, $Task->getStatus());
		usleep(500000);
		$this->assertEquals(TaskStatus::Finished, $Task->getStatus());
		$this->assertEquals(false, $Task->isSucceed());
		$error = $Task->getError();
		$this->assertEquals(true, $error instanceof GenericError);
		$this->assertEquals(BuddyRequestError::class . ': ' . $errorMessage, $error->getMessage());
		$this->assertEquals($errorMessage, $error->getResponseError());
	}
}

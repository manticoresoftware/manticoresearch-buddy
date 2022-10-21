<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Lib\Task;
use Manticoresearch\Buddy\Lib\TaskStatus;
use PHPUnit\Framework\TestCase;

class TaskTest extends TestCase {
	public function testTaskParallelRunSucceed(): void {
		$taskId = uniqid();
		$Task = Task::create(
			$taskId, function (): bool {
				usleep(2000000);
				return true;
			}
		);

		$this->assertEquals(TaskStatus::Pending, $Task->getStatus());
		$Task->run();
		$this->assertEquals(TaskStatus::Running, $Task->getStatus());
		usleep(2500000);
		$this->assertEquals(TaskStatus::Finished, $Task->getStatus());
		$this->assertEquals(true, $Task->getResult());
	}
}

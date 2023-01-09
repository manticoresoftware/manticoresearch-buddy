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
use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class HungRequestTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * @var string $searchdLog
	 */
	protected string $searchdLog;

	/**
	 * @var string $searchdLogFilepath
	 */
	protected string $searchdLogFilepath;

	/**
	 * @var int $debugMode
	 */
	protected static int $debugMode = 0;

	public function setUp(): void {
	}

	public function tearDown(): void {
	}

	public function testDeferredHungRequestHandling(): void {
		$port = static::getListenHttpPort();
		$task1 = Task::create(...$this->generateTaskArgs([$port, 'test 3/deferred']));
		$task2 = Task::create(...$this->generateTaskArgs([$port, 'show queries']));
		$task1->run();
		usleep(500000);
		$task2->run();
		usleep(500000);

		$this->assertEquals(TaskStatus::Finished, $task1->getStatus());
		$this->assertEquals(true, $task1->isSucceed());
		$res1 = (array)$task1->getResult();
		$this->assertEquals(TaskStatus::Finished, $task2->getStatus());
		$this->assertEquals(true, $task2->isSucceed());
		$res2 = (array)$task2->getResult();
		// Making sure that the id of the last running query is the id of the hung request task
		if (!is_array($res1[0]) || !is_array($res2[0])) {
			$this->fail();
		}
		$this->assertEquals($res1[0]['data'][0]['id'], $res2[0]['data'][2]['id']);
		sleep(4);
	}

	/**
	 * @depends testDeferredHungRequestHandling
	 */
	public function testHungRequestHandling(): void {
		$port = static::getListenHttpPort();
		$task1 = Task::create(...$this->generateTaskArgs([$port, 'test 3']));
		$task2 = Task::create(...$this->generateTaskArgs([$port, 'show queries']));
		$task1->run();
		usleep(500000);
		$task2->run();
		usleep(500000);

		$this->assertEquals(TaskStatus::Running, $task1->getStatus());
		$this->assertEquals(TaskStatus::Running, $task2->getStatus());
		sleep(4);
		$this->assertEquals(TaskStatus::Finished, $task1->getStatus());
		$this->assertEquals([[]], $task1->getResult());
		$this->assertEquals(TaskStatus::Finished, $task2->getStatus());
		$this->assertEquals(true, $task2->isSucceed());
	}

	/**
	 * @param array{0:int,1:string} $taskFnArgs
	 * @return array{0:Closure,1:array{0:int,1:string}}
	 */
	protected function generateTaskArgs(array $taskFnArgs): array {
		return [
			function (int $port, string $query): array {
				$output = [];
				exec("curl -s 127.0.0.1:$port/cli -d '$query' 2>&1", $output);
				/** @var array<int,array{error:string,data:array<int,array<string,string>>,total?:string,columns?:string}> $result */
				$result = (array)json_decode($output[0] ?? '{}', true);
				return $result;
			},
			$taskFnArgs,
		];
	}
}

<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Task\TaskStatus;
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
		$task1 = Task::create(...$this->generateTaskArgs([$port, 'test 4/deferred']))->run();
		$task2 = Task::create(...$this->generateTaskArgs([$port, 'show queries']))->run();

		$this->assertEquals(TaskStatus::Finished, $task1->getStatus());
		$this->assertEquals(true, $task1->isSucceed());
		/** @var array<mixed> $res1 */
		$res1 = $task1->getResult()->getStruct();
		$this->assertEquals(TaskStatus::Finished, $task2->getStatus());
		$this->assertEquals(true, $task2->isSucceed());
		/** @var array<mixed> $res2 */
		$res2 = $task2->getResult()->getStruct();
		// Making sure that the id of the last running query is the id of the hung request task
		if (!is_array($res1[0]) || !is_array($res2[0])) {
			$this->fail();
		}

		$actualId = 0;
		foreach ($res2[0]['data'] as $row) {
			if ($row['query'] !== 'test 4/deferred') {
				continue;
			}

			$actualId = $row['id'];
		}
		$this->assertEquals($res1[0]['data'][0]['id'], $actualId);
		sleep(4);
	}

	/**
	 * @depends testDeferredHungRequestHandling
	 */
	public function testHungRequestHandling(): void {
		$port = static::getListenHttpPort();
		$t = time();
		$task1 = Task::create(...$this->generateTaskArgs([$port, 'test 3']))->run();
		$task2 = Task::create(...$this->generateTaskArgs([$port, 'show queries']))->run();
		$diff = time() - $t;
		// We check diff here cuz we usin exec, that is blocking for coroutine
		$this->assertEquals(3, $diff);
		$this->assertEquals(TaskStatus::Finished, $task1->getStatus());
		$this->assertEquals(TaskStatus::Finished, $task2->getStatus());
		$this->assertEquals([['total' => 0, 'error' => '', 'warning' => '']], $task1->getResult()->getStruct());
		$this->assertEquals(TaskStatus::Finished, $task2->getStatus());
		$this->assertEquals(true, $task2->isSucceed());
	}

	/**
	 * @param array{0:int,1:string} $taskFnArgs
	 * @return array{0:Closure,1:array{0:int,1:string}}
	 */
	protected function generateTaskArgs(array $taskFnArgs): array {
		return [
			static function (int $port, string $query): TaskResult {
				$output = [];
				exec("curl -s 127.0.0.1:$port/cli_json -d '$query' 2>&1", $output);
				/** @var array<int,array{error:string,data:array<int,array<string,string>>,total?:string,columns?:string}> $result */
				$result = (array)simdjson_decode($output[0] ?? '{}', true);
				return TaskResult::raw($result);
			},
			$taskFnArgs,
		];
	}
}

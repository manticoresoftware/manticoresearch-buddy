<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding {
	if (!function_exists(__NAMESPACE__ . '\\simdjson_decode')) {
		function simdjson_decode(string $json, bool $assoc = false): mixed {
			return json_decode($json, $assoc, 512, JSON_THROW_ON_ERROR);
		}
	}
}

namespace {

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

	use Manticoresearch\Buddy\Base\Plugin\Sharding\Operator;
	use Manticoresearch\Buddy\Base\Plugin\Sharding\State;
	use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
	use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
	use PHPUnit\Framework\TestCase;

	final class OperatorInFlightRebalanceTest extends TestCase {

		public function testHasInFlightRebalanceReturnsTrueForQueuedRunningOrPausedStates(): void {
			$operator = $this->makeOperatorWithRebalanceStates(['queued', 'running', 'paused']);
			$this->assertTrue($operator->hasInFlightRebalance());
		}

		public function testHasInFlightRebalanceIgnoresCompletedStates(): void {
			$operator = $this->makeOperatorWithRebalanceStates(['completed', 'failed']);
			$this->assertFalse($operator->hasInFlightRebalance());
		}

		/**
		 * @param list<string> $states
		 */
		private function makeOperatorWithRebalanceStates(array $states): Operator {
			$rows = [];
			foreach ($states as $idx => $state) {
				$rows[] = [
				'key' => "rebalance:t{$idx}",
				'value' => $state,
				];
			}

			$client = $this->createMock(Client::class);
			$client->method('sendRequest')->willReturn($this->createResponse($rows));

			$operator = (new ReflectionClass(Operator::class))->newInstanceWithoutConstructor();
			$operator->state = new State($client);
			return $operator;
		}

		/**
		 * @param array<array{key:string,value:string}> $rows
		 */
		private function createResponse(array $rows): Response {
			$response = $this->createMock(Response::class);
			$response->method('getResult')->willReturn(
				\Manticoresearch\Buddy\Core\Network\Struct::fromData(
					[[
					'error' => '',
					'warning' => '',
					'total' => sizeof($rows),
					'data' => $rows,
					]]
				)
			);
			$response->method('hasError')->willReturn(false);
			$response->method('getError')->willReturn('');
			return $response;
		}
	}
}

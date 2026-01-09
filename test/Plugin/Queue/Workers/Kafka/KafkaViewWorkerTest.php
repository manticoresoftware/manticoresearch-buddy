<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka\View;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use PHPUnit\Framework\TestCase;

final class KafkaViewWorkerTest extends TestCase {

	public function testRunUsesDefaultSqlEndpointAndInsertsRows(): void {
		$client = $this->createMock(Client::class);
		$buffer = 'kafka_buffer';
		$destination = 'kafka_destination';
		$query = "SELECT id, name FROM $buffer";

		$descResponse = Response::fromBody(
			(string)json_encode(
				[[
				'error' => '',
				'warning' => '',
				'total' => 2,
				'data' => [
				['Field' => 'id', 'Type' => 'int'],
				['Field' => 'name', 'Type' => 'string'],
				],
				]]
			)
		);

		$readResponse = Response::fromBody(
			(string)json_encode(
				[[
				'error' => '',
				'warning' => '',
				'total' => 2,
				'data' => [
				['id' => '1', 'name' => 'Alice'],
				['id' => '2', 'name' => 'Bob'],
				],
				]]
			)
		);

		$okResponse = Response::fromBody(
			(string)json_encode(
				[[
				'error' => '',
				'warning' => '',
				'total' => 0,
				'data' => [],
				]]
			)
		);

		$expectedInsert = "REPLACE INTO $destination (id, name) VALUES (1,'Alice'),(2,'Bob')";
		$expectedTruncate = "TRUNCATE TABLE $buffer";

		$client->expects($this->exactly(4))
			->method('sendRequest')
			->withConsecutive(
				[$this->equalTo('DESC ' . $destination)],
				[$this->equalTo($query)],
				[$this->equalTo($expectedInsert)],
				[$this->equalTo($expectedTruncate)]
			)
			->willReturnOnConsecutiveCalls($descResponse, $readResponse, $okResponse, $okResponse);

		$view = new View($client, $buffer, $destination, $query);
		$this->assertTrue($view->run());
	}
}

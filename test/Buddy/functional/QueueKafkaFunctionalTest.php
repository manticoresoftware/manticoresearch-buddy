<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka\View;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

final class QueueKafkaFunctionalTest extends TestCase {
	use TestFunctionalTrait;

	private const string KAFKA_BROKER = 'kafka:9092';

	private string $source;
	private string $destination;
	private string $view;
	private string $topic;
	private string $group;

	protected function setUp(): void {
		$suffix = bin2hex(random_bytes(4));
		$this->source = 'functional_kafka_' . $suffix;
		$this->destination = 'functional_kafka_dest_' . $suffix;
		$this->view = 'functional_kafka_view_' . $suffix;
		$this->topic = 'functional-queue-kafka-' . $suffix;
		$this->group = 'functional_queue_group_' . $suffix;
	}

	protected function tearDown(): void {
		$this->cleanupObjects();
	}

	public function testKafkaQueueFullLifecycleWithSourceRecreation(): void {
		$this->cleanupObjects();

		$this->createQueueObjects($this->group, 1);
		$this->insertMockKafkaRows();
		$this->runViewWorker();
		$this->assertDestinationRows();

		$this->assertQueryResult('SHOW SOURCES', $this->source);
		$this->assertQueryResult('SHOW SOURCE ' . $this->source, 'CREATE SOURCE ' . $this->source);
		$this->assertQueryResult('SHOW MATERIALIZED VIEW ' . $this->view, 'suspended: 0');
		$this->assertQueryResultContainsError(
			'CREATE MATERIALIZED VIEW ' . $this->view . ' TO ' . $this->destination . ' AS ' .
			'SELECT id, term AS name, abbrev AS short_name FROM ' . $this->source,
			"View $this->view already exist"
		);

		static::runSqlQuery('ALTER MATERIALIZED VIEW ' . $this->view . ' suspended=1');
		$this->assertQueryResult('SHOW MATERIALIZED VIEW ' . $this->view, 'suspended: 1');

		static::runSqlQuery('DROP SOURCE ' . $this->source);
		$this->assertSourceTableDoesNotExist();
		$this->assertViewRows([$this->source . '_0']);

		$this->createSource($this->group . '_four', 4);
		$this->assertViewRows(
			[
				$this->source . '_0',
				$this->source . '_1',
				$this->source . '_2',
				$this->source . '_3',
			]
		);

		static::runSqlQuery('DROP SOURCE ' . $this->source);
		$this->createSource($this->group . '_one', 1);
		$this->assertViewRows([$this->source . '_0']);

		static::runSqlQuery('ALTER MATERIALIZED VIEW ' . $this->view . ' suspended=0');
		$this->assertQueryResult('SHOW MATERIALIZED VIEW ' . $this->view, 'suspended: 0');
	}

	public function testCreateMaterializedViewCanRetryAfterMissingDestinationIsCreated(): void {
		$this->cleanupObjects();
		$this->createSource($this->group, 1);

		$query = 'CREATE MATERIALIZED VIEW ' . $this->view . ' TO ' . $this->destination . ' AS ' .
			'SELECT id, term AS name, abbrev AS short_name FROM ' . $this->source;
		$this->assertQueryResultContainsError($query, 'Destination table non exist');

		static::runSqlQuery(
			'CREATE TABLE ' . $this->destination .
			' (id bigint, name text, short_name text)'
		);
		static::runSqlQuery($query);

		$this->assertQueryResult('SHOW MATERIALIZED VIEW ' . $this->view, 'suspended: 0');
	}

	private function cleanupObjects(): void {
		static::runSqlQuery('DROP MATERIALIZED VIEW ' . $this->view);
		static::runSqlQuery('DROP SOURCE ' . $this->source);
		static::runSqlQuery('DROP TABLE IF EXISTS ' . $this->destination);
	}

	private function createQueueObjects(string $group, int $numConsumers): void {
		$this->createSource($group, $numConsumers);
		static::runSqlQuery(
			'CREATE TABLE ' . $this->destination .
			' (id bigint, name text, short_name text)'
		);
		static::runSqlQuery(
			'CREATE MATERIALIZED VIEW ' . $this->view . ' TO ' . $this->destination . ' AS ' .
			'SELECT id, term AS name, abbrev AS short_name FROM ' . $this->source
		);
	}

	private function createSource(string $group, int $numConsumers): void {
		static::runSqlQuery(
			'CREATE SOURCE ' . $this->source . ' (id bigint, term text, abbrev text) ' .
			"type='kafka' broker_list='" . self::KAFKA_BROKER . "' topic_list='" . $this->topic . "' " .
			"consumer_group='$group' num_consumers='$numConsumers' batch='50'"
		);
	}

	private function insertMockKafkaRows(): void {
		static::runSqlQuery(
			'REPLACE INTO system.buffer_' . $this->source . '_0 (id, term, abbrev) VALUES ' .
			"(1, 'Manticore Search', 'MCS')," .
			"(2, 'Materialized View', 'MVA')," .
			"(3, 'Kafka Source', 'SRC')"
		);
	}

	private function runViewWorker(): void {
		$client = new Client('http://127.0.0.1:' . static::getListenHttpPort());
		$client->setDelegatedUser('system.buddy');
		$buffer = 'system.buffer_' . $this->source . '_0';
		$destination = $this->destination;
		$query = "SELECT id, term AS name, abbrev AS short_name FROM $buffer LIMIT 50";

		$view = new View($client, $buffer, $destination, $query);
		$this->assertTrue($view->run());
	}

	private function assertDestinationRows(): void {
		$result = static::runHttpQuery('SELECT id, name, short_name FROM ' . $this->destination);
		$this->assertIsArray($result[0]);
		$this->assertSame('', $result[0]['error']);

		$rows = [];
		foreach ($result[0]['data'] as $row) {
			$rows[] = $row['id'] . "\t" . $row['name'] . "\t" . $row['short_name'];
		}
		sort($rows);
		$this->assertSame(
			[
				"1\tManticore Search\tMCS",
				"2\tMaterialized View\tMVA",
				"3\tKafka Source\tSRC",
			],
			$rows
		);
	}

	private function assertSourceTableDoesNotExist(): void {
		$output = $this->runMysqlRaw("SHOW TABLES FROM system LIKE 'source_" . $this->source . "'");
		$this->assertSame(0, $output['code'], $output['output']);
		$this->assertSame('', trim($output['output']));
	}

	/**
	 * @param array<string> $expectedSources
	 */
	private function assertViewRows(array $expectedSources): void {
		sort($expectedSources);
		$result = static::runHttpQuery(
			'SELECT source_name FROM system.materialized_view_' . $this->view
		);
		$this->assertIsArray($result[0]);
		$this->assertSame('', $result[0]['error']);

		$actualSources = [];
		foreach ($result[0]['data'] as $row) {
			$actualSources[] = $row['source_name'];
		}
		sort($actualSources);
		$this->assertSame($expectedSources, $actualSources);
	}

	/**
	 * @return array{code:int,output:string}
	 */
	private function runMysqlRaw(string $query): array {
		$payloadFile = \sys_get_temp_dir() . '/payload-' . uniqid() . '.data';
		file_put_contents($payloadFile, $query . ';');
		$command = sprintf(
			'mysql -N -B -P%d -h127.0.0.1 < %s 2>&1',
			static::getListenSqlPort(),
			escapeshellarg($payloadFile)
		);
		exec($command, $output, $code);
		unlink($payloadFile);

		return ['code' => $code, 'output' => implode(PHP_EOL, $output)];
	}
}

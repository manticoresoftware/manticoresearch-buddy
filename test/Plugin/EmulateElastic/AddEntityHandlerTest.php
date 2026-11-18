<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if not, see <https://www.gnu.org/licenses/>.
*/

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\AddEntityHandler;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\BuddyTest\Plugin\EmulateElastic\AddEntityHandlerClient;
use Manticoresearch\BuddyTest\Plugin\EmulateElastic\AddEntityHandlerRecords;
use PHPUnit\Framework\TestCase;

final class AddEntityHandlerTest extends TestCase {
	public function testCreateInsertsNewEntity(): void {
		$records = new AddEntityHandlerRecords(false);

		$result = self::addEntity($records, true);

		$this->assertSame('created', $result);
		$this->assertTrue($records->entityExists);
		$this->assertQueryMatches(
			$records,
			'/^INSERT INTO system\.kibana_entities'
			. '\(id, _id, _index, _index_alias, _type, _source\) VALUES\(\d+, /'
		);
		$this->assertQueryMissing($records, 'DELETE FROM system.kibana_entities');
	}

	public function testCreateReturnsConflictForExistingEntity(): void {
		$records = new AddEntityHandlerRecords(true);

		try {
			self::addEntity($records, true);
			$this->fail('Expected a version conflict');
		} catch (GenericError $error) {
			$this->assertSame(409, $error->getResponseErrorCode());
			/** @var array{error:array{type:string}} $errorBody */
			$errorBody = $error->getResponseErrorBody();
			$this->assertSame(
				'version_conflict_engine_exception',
				$errorBody['error']['type']
			);
		}

		$this->assertQueryMissing($records, 'INSERT INTO system.kibana_entities');
	}

	public function testCreateRemovesLegacyDuplicatesBeforeReturningConflict(): void {
		$records = new AddEntityHandlerRecords(true);
		$records->hasDuplicates = true;

		try {
			self::addEntity($records, true);
			$this->fail('Expected a version conflict');
		} catch (GenericError $error) {
			$this->assertSame(409, $error->getResponseErrorCode());
		}

		$this->assertFalse($records->hasDuplicates);
		$this->assertQueryMatches(
			$records,
			'/^DELETE FROM system\.kibana_entities WHERE id IN \(122,121\)$/'
		);
	}

	public function testCreateTranslatesConcurrentInsertConflict(): void {
		$records = new AddEntityHandlerRecords(false);
		$records->failNextInsert = true;

		try {
			self::addEntity($records, true);
			$this->fail('Expected a version conflict');
		} catch (GenericError $error) {
			$this->assertSame(409, $error->getResponseErrorCode());
		}

		$this->assertSame(
			2,
			sizeof(
				array_filter(
					$records->queries,
					fn (string $query) => str_starts_with(
						$query,
						'SELECT id FROM system.kibana_entities'
					)
				)
			)
		);
	}

	public function testDocReplacesExistingEntityWithoutDuplicates(): void {
		$records = new AddEntityHandlerRecords(true);

		$result = self::addEntity($records, false);

		$this->assertSame('updated', $result);
		$this->assertQueryMatches($records, '/^DELETE FROM system\.kibana_entities /');
		$this->assertQueryMatches(
			$records,
			'/^REPLACE INTO system\.kibana_entities'
			. '\(id, _id, _index, _index_alias, _type, _source\) VALUES\(\d+, /'
		);
	}

	public function testDocCreatesMissingEntity(): void {
		$records = new AddEntityHandlerRecords(false);

		$result = self::addEntity($records, false);

		$this->assertSame('created', $result);
		$this->assertQueryMissing($records, 'DELETE FROM system.kibana_entities');
		$this->assertQueryMatches($records, '/^REPLACE INTO system\.kibana_entities/');
	}

	private static function addEntity(AddEntityHandlerRecords $records, bool $createOnly): string {
		return AddEntityHandler::add(
			'{"config":{"buildNum":8743}}',
			'config:3.4.0',
			'config',
			'.kibana',
			'.kibana',
			new AddEntityHandlerClient($records),
			$createOnly
		);
	}

	private function assertQueryMatches(AddEntityHandlerRecords $records, string $pattern): void {
		$this->assertNotEmpty(
			array_filter(
				$records->queries,
				fn (string $query) => (bool)preg_match($pattern, $query)
			),
			"Failed to find query matching {$pattern}"
		);
	}

	private function assertQueryMissing(AddEntityHandlerRecords $records, string $prefix): void {
		$this->assertEmpty(
			array_filter(
				$records->queries,
				fn (string $query) => str_starts_with($query, $prefix)
			),
			"Unexpected query starting with {$prefix}"
		);
	}
}

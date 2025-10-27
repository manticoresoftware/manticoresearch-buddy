<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class ReplaceTest extends TestCase {

	use TestFunctionalTrait;

	public function testReplaceWithPositiveIdWorksWell(): void {
		echo "\nTesting REPLACE with positive ID\n";

		static::runSqlQuery('DROP TABLE IF EXISTS replace_test');
		static::runSqlQuery('CREATE TABLE replace_test (title text, content text stored)');
		static::runSqlQuery(
			'INSERT INTO replace_test (id, title, content) '.
			"VALUES (1, 'Original Title', 'Original Content')"
		);

		$result = static::runSqlQuery('SELECT * FROM replace_test WHERE id = 1');
		$this->assertStringContainsString('Original Title', implode(PHP_EOL, $result));
		$this->assertStringContainsString('Original Content', implode(PHP_EOL, $result));

		static::runSqlQuery(
			'REPLACE INTO replace_test '.
			"SET title = 'Updated Title', content = 'Updated Content' WHERE id = 1"
		);

		$result = static::runSqlQuery('SELECT * FROM replace_test WHERE id = 1');
		$this->assertStringContainsString('Updated Title', implode(PHP_EOL, $result));
		$this->assertStringContainsString('Updated Content', implode(PHP_EOL, $result));

		static::runSqlQuery('DROP TABLE IF EXISTS replace_test');
	}

	public function testReplaceWithNegativeIdWorksWell(): void {
		echo "\nTesting REPLACE with negative ID (UINT64 conversion)\n";

		static::runSqlQuery('DROP TABLE IF EXISTS replace_negative_test');
		static::runSqlQuery('CREATE TABLE replace_negative_test (title text, content text stored)');

		$negativeId = -1;


		$insertQuery = 'INSERT INTO replace_negative_test (id, title, content) '.
			"VALUES ('$negativeId', 'Original Title', 'Original Content')";
		static::runSqlQuery($insertQuery);

		$selectQuery = "SELECT * FROM replace_negative_test WHERE id = $negativeId";
		$result = static::runSqlQuery($selectQuery);

		$this->assertStringContainsString('Original Title', implode(PHP_EOL, $result));
		$this->assertStringContainsString('Original Content', implode(PHP_EOL, $result));

		static::runSqlQuery(
			'REPLACE INTO replace_negative_test '.
			"SET title = 'Updated Title Negative', content = 'Updated Content Negative' WHERE id = $negativeId"
		);

		$result = static::runSqlQuery("SELECT * FROM replace_negative_test WHERE id = $negativeId");
		$this->assertStringContainsString('Updated Title Negative', implode(PHP_EOL, $result));
		$this->assertStringContainsString('Updated Content Negative', implode(PHP_EOL, $result));

		static::runSqlQuery('DROP TABLE IF EXISTS replace_negative_test');
	}

	public function testReplaceWithNegativeIdNoExistingRecord(): void {
		echo "\nTesting REPLACE with negative ID when record doesn't exist\n";

		static::runSqlQuery('DROP TABLE IF EXISTS replace_negative_new_test');
		static::runSqlQuery('CREATE TABLE replace_negative_new_test (title text, content text stored)');

		$negativeId = -5;

		static::runSqlQuery(
			'REPLACE INTO replace_negative_new_test '.
			"SET title = 'New Title', content = 'New Content' WHERE id = $negativeId"
		);

		$result = static::runSqlQuery("SELECT * FROM replace_negative_new_test WHERE id = $negativeId");
		$this->assertStringContainsString('New Title', implode(PHP_EOL, $result));
		$this->assertStringContainsString('New Content', implode(PHP_EOL, $result));

		static::runSqlQuery('DROP TABLE IF EXISTS replace_negative_new_test');
	}

	public function testReplaceWithMultipleFields(): void {
		echo "\nTesting REPLACE with multiple fields including negative ID\n";

		static::runSqlQuery('DROP TABLE IF EXISTS replace_multi_test');
		static::runSqlQuery(
			'CREATE TABLE replace_multi_test '.
			'(title text, content text stored, category text, price float)'
		);

		$negativeId = -100;
		$insertQuery = 'INSERT INTO replace_multi_test (id, title, content, category, price) '.
			"VALUES ('$negativeId', 'Product 1', 'Description 1', 'Electronics', 99.99)";

		static::runSqlQuery($insertQuery);


		$selectQuery = "SELECT * FROM replace_multi_test WHERE id = $negativeId";

		$result = static::runSqlQuery($selectQuery);

		$this->assertStringContainsString('Product 1', implode(PHP_EOL, $result));
		$this->assertStringContainsString('99.989998', implode(PHP_EOL, $result));

		static::runSqlQuery(
			'REPLACE INTO replace_multi_test '.
			"SET title = 'Product 1 Updated', price = 79.99 WHERE id = $negativeId"
		);

		$result = static::runSqlQuery("SELECT * FROM replace_multi_test WHERE id = $negativeId");
		$this->assertStringContainsString('Product 1 Updated', implode(PHP_EOL, $result));
		$this->assertStringContainsString('79.989998', implode(PHP_EOL, $result));
		$this->assertStringContainsString('Description 1', implode(PHP_EOL, $result));
		$this->assertStringContainsString('Electronics', implode(PHP_EOL, $result));

		static::runSqlQuery('DROP TABLE IF EXISTS replace_multi_test');
	}

	public function testReplaceElasticLikeWithPositiveId(): void {
		echo "\nTesting Elastic-like REPLACE with positive ID\n";

		static::runSqlQuery('DROP TABLE IF EXISTS elastic_replace_test');
		static::runSqlQuery('CREATE TABLE elastic_replace_test (title text, content text stored)');
		static::runSqlQuery(
			'INSERT INTO elastic_replace_test (id, title, content) VALUES '.
			"(1, 'Original', 'Original Content')"
		);

		$updatePayload = '{"doc":{"title":"Updated via Elastic","content":"Updated Content via Elastic"}}';
		static::runHttpQuery($updatePayload, true, 'elastic_replace_test/_update/1');

		$queryResult = static::runSqlQuery('SELECT * FROM elastic_replace_test WHERE id = 1');
		$this->assertStringContainsString('Updated via Elastic', implode(PHP_EOL, $queryResult));

		static::runSqlQuery('DROP TABLE IF EXISTS elastic_replace_test');
	}
}

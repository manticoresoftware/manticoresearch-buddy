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

/**
 * This is low level test of SHOW VARIABLES response
 * to make sure that we have same response as we use in Buddy
 * Otherwise just we need to update our code
 */
class ShowVariablesTest extends TestCase {

	use TestFunctionalTrait;

	const FIELDS = [
		'autocommit',
		'auto_optimize',
		'optimize_cutoff',
		'collation_connection',
		'query_log_format',
		'session_read_only',
		'log_level',
		'max_allowed_packet',
		'character_set_client',
		'character_set_connection',
		'grouping_in_utc',
		'last_insert_id',
		'pseudo_sharding',
		'secondary_indexes',
		'accurate_aggregation',
		'threads_ex_effective',
		'threads_ex',
	];

	public function testShowVariables(): void {
		$query = 'SHOW VARIABLES';
		$this->assertQueryResult($query, ['Variable_name', 'Value', ...static::FIELDS]);
	}
}

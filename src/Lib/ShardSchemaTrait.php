<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Lib;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

/**
 * Shared shard-discovery for plugins that fan a single-table command out to
 * the physical shards behind a sharded (type='shard') or legacy distributed
 * (type='distributed') wrapper.
 *
 * Used by Truncate, Optimize, Flush handlers. Vendor's
 * Client::getTableShards() only knows the legacy distributed form, so we
 * parse SHOW CREATE TABLE here to support both.
 */
trait ShardSchemaTrait
{
	/**
	 * @param Client $client
	 * @param string $table
	 * @return array<array{name:string,url:string}>
	 */
	protected function getShards(Client $client, string $table): array {
		/** @var array{0:array{data:array<array{"Create Table":string}>}} $res */
		$res = $client
			->sendRequest("SHOW CREATE TABLE $table OPTION force=1")
			->getResult();
		$tableSchema = $res[0]['data'][0]['Create Table'] ?? '';
		if (!$tableSchema) {
			throw GenericError::create("There is no such table: {$table}");
		}
		if (!str_contains($tableSchema, "type='distributed'")
			&& !str_contains($tableSchema, "type='shard'")) {
			throw GenericError::create("Table {$table} is not a distributed or sharded table");
		}
		if (!preg_match_all("/local='(?P<local>[^']+)'|agent='(?P<agent>[^']+)'/ius", $tableSchema, $m)) {
			throw GenericError::create('Failed to match shards from the schema');
		}
		$shards = [];
		foreach (array_filter($m['local']) as $name) {
			$shards[] = ['name' => $name, 'url' => ''];
		}
		foreach (array_filter($m['agent']) as $agent) {
			$ex = explode('|', $agent);
			$host = strtok($ex[0], ':');
			$port = (int)strtok(':');
			$name = (string)strtok(':');
			$shards[] = ['name' => $name, 'url' => "$host:$port"];
		}
		return $shards;
	}
}

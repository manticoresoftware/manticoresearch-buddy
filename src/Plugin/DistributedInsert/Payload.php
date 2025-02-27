<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\DistributedInsert;

use Ds\Vector;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * Request for Backup command that has parsed parameters from SQL
 * @phpstan-type Batch array<non-falsy-string,Vector<Struct<int|string,mixed>>>
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	/** @var string */
	public string $path;

	/** @var string */
	public string $type;

	/** @var Batch */
	public array $batch;

	public function __construct() {
	}

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'The plugin enables data insertion into distributed sharded tables.';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$self->path = $request->path;

		$self->batch = static::parsePayload($request);
		$self->type = match ($request->endpointBundle) {
			Endpoint::Sql, Endpoint::Cli => 'sql',
			Endpoint::Insert => 'insert',
			Endpoint::Replace => 'replace',
			Endpoint::Bulk => 'bulk',
			Endpoint::Update => 'update',
			Endpoint::Delete => 'delete',
			default => throw QueryParseError::create('Unsupported endpoint bundle'),
		};

		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$hasErrorMessage = stripos($request->error, 'not support insert') !== false;

		// Insert or Replace
		$hasMatch = ($request->endpointBundle === Endpoint::Insert
			|| $request->endpointBundle === Endpoint::Replace)
			&& $hasErrorMessage
		;

		// Bulk
		if (!$hasMatch) {
			$hasMatch = $request->endpointBundle === Endpoint::Bulk
				&& $hasErrorMessage;
		}

		// Update
		if (!$hasMatch) {
			$hasMatch = $request->endpointBundle === Endpoint::Update;
		}

		// Delete
		if (!$hasMatch) {
			$hasMatch = $request->endpointBundle === Endpoint::Delete;
		}

		// SQL
		if (!$hasMatch) {
			$isSqlEndpoint = $request->endpointBundle === Endpoint::Sql
				|| $request->endpointBundle === Endpoint::Cli;
			$hasMatch = $isSqlEndpoint
				&& ($request->command === 'insert' || $request->command === 'replace')
				&& $hasErrorMessage
			;
		}

		return $hasMatch;
	}

	/**
	 * @param Request $request
	 * @return Batch
	 * @throws QueryParseError
	 */
	protected static function parsePayload(Request $request): array {
		if ($request->endpointBundle === Endpoint::Bulk) {
			return static::parseBulkPayload($request);
		}

		if ($request->endpointBundle === Endpoint::Cli
			|| $request->endpointBundle === Endpoint::Sql) {
			return static::parseSqlPayload($request);
		}

		return static::parseSinglePayload($request);
	}

	/**
	 * @param Request $request
	 * @return Batch
	 */
	protected static function parseBulkPayload(Request $request): array {
		$batch = [];
		$table = '';
		$rows = explode("\n", trim($request->payload));
		$rowCount = sizeof($rows);
		for ($i = 0; $i < $rowCount; $i++) {
			if (empty($rows[$i])) {
				continue;
			}

			$table = static::processBulkRow($rows[$i], $batch, $table);
		}
		/** @var Batch $batch */
		return $batch;
	}

	/**
	 * @param string $row
	 * @param Batch &$batch
	 * @param string $table
	 * @return string
	 * @throws QueryParseError
	 */
	protected static function processBulkRow(
		string $row,
		array &$batch,
		string $table,
	): string {
		/** @var Struct<int|string,array<string,mixed>> $struct */
		$struct = Struct::fromJson($row);
		if (isset($struct['index']['_index'])) { // _bulk
			/** @var string $table */
			$table = $struct['index']['_index'];
			if (!isset($batch[$table])) {
				$batch[$table] = new Vector();
			}
			$batch[$table][] = $struct;
			return $table;
		}


		foreach (['insert', 'update', 'delete'] as $key) {
			if (!isset($struct[$key])) {
				continue;
			}
			// bulk
			/** @var string $table */
			$table = $struct[$key]['table'] ?? $struct[$key]['_index'];
			if (!isset($batch[$table])) {
				$batch[$table] = new Vector();
			}
			$batch[$table][] = $struct;
			return $table;
		}

		if (!$table) {
			QueryParseError::throw('Cannot find table name');
		}

		$batch[$table][] = $struct;
		return $table;
	}

	/**
	 * @param Request $request
	 * @return Batch
	 * @throws QueryParseError
	 */
	protected static function parseSinglePayload(Request $request): array {
		$struct = Struct::fromJson($request->payload);
		/** @var string $table */
		$table = $struct['table'] ?? $struct['index'];
		/** @var Batch */
		return [$table => new Vector([$struct])];
	}

	/**
	 * Parse the SQL request
	 * @param Request $request
	 * @return Batch
	 */
	protected static function parseSqlPayload(Request $request): array {
		static $queryPattern = '/^(insert|replace)\s+into\s+'
			. '`?([a-z][a-z\_\-0-9]*)`?'
			. '(?:\s*\(([^)]+)\))?\s+'
			. 'values\s*\((.*)\)/ius';
		static $valuePattern = "/'(?:[^'\\\\]*(?:\\\\.[^'\\\\]*)*)'"
			. '|\\d+(?:\\.\\d+)?|NULL|\\{(?:[^{}]|\\{[^{}]*\\})*\\}/';

		preg_match($queryPattern, $request->payload, $matches);
		$type = strtolower($matches[1]);
		$table = $matches[2] ?? null;
		$fields = [];
		if (isset($matches[3])) {
			$fields = array_map('trim', explode(',', $matches[3]));
			$fields = array_map(
				function ($field) {
					return trim($field, '`');
				}, $fields
			);
		}
		if (!$fields) {
			throw QueryParseError::create(strtoupper($type) . ' into a sharded table requires specifying the fields.');
		}
		if (!$table) {
			throw QueryParseError::create('Failed to parse table from the query');
		}

		// It's time to parse values
		if (!isset($matches[4])) {
			throw QueryParseError::create('Failed to parse values from the query');
		}

		$values = &$matches[4];
		preg_match_all($valuePattern, $values, $matches);
		$values = &$matches[0];
		/* $values = array_map(trim(...), $matches[0]); */

		$fieldCount = sizeof($fields);
		$valueCount = sizeof($values);
		/** @var Vector<Struct<int|string,mixed>> */
		$batch = new Vector();
		$doc = [];
		for ($i = 0; $i < $valueCount; $i++) {
			$index = ($i + 1) % $fieldCount;
			$doc[$fields[$index]] = trim($values[$i], "'");
			// We have edge case when single field and last is first also
			$isLast = $index === 0;
			if (!$isLast) {
				continue;
			}
			$row = [];
			if (isset($doc['id'])) {
				$row['id'] = (int)$doc['id'];
				unset($doc['id']);
			}
			$row['doc'] = $doc;
			$batch[] = Struct::fromData([$type => $row]);
			$doc = [];
		}
		/** @var Batch */
		return [$table => $batch];
	}
}

<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\DistributedInsert;

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * Request for Backup command that has parsed parameters from SQL
 * @phpstan-type Batch array<non-falsy-string,non-empty-array<int<0,max>, Struct<int|string,mixed>>>
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
			Endpoint::Insert => 'insert',
			Endpoint::Replace => 'replace',
			Endpoint::Bulk => 'bulk',
			Endpoint::Update => 'update',
			Endpoint::Delete => 'delete',
			default => throw new \Exception('Unsupported endpoint bundle'),
		};

		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		// Insert or Replace
		$hasMatch = ($request->endpointBundle === Endpoint::Insert
			|| $request->endpointBundle === Endpoint::Replace)
			&& stripos($request->error, 'not support insert') !== true
		;

		// Bulk
		if (!$hasMatch) {
			$hasMatch = $request->endpointBundle === Endpoint::Bulk;
		}

		// Update
		if (!$hasMatch) {
			$hasMatch = $request->endpointBundle === Endpoint::Update;
		}

		// Delete
		if (!$hasMatch) {
			$hasMatch = $request->endpointBundle === Endpoint::Delete;
		}

		return $hasMatch;
	}

	/**
	 * @param Request $request
	 * @return Batch
	 * @throws QueryParseError
	 */
	protected static function parsePayload(Request $request): array {
		if ($request->endpointBundle !== Endpoint::Bulk) {
			$struct = Struct::fromJson($request->payload);
			/** @var string $table */
			$table = $struct['table'] ?? $struct['index'];

			// We support 2 ways of cluster: as key or in table as prefix:
			/** @var string $cluster */
			$cluster = $struct['cluster'] ?? '';
			if (!$cluster) {
				[$cluster, $table] = static::parseClaster($table);
			}

			unset($struct['table'], $struct['index'], $struct['cluster']);
			$struct['table'] = '%table%';
			if (!isset($struct['id'])) {
				$struct['id'] = '%id%';
			}

			return ["$cluster:$table" => [$struct]];
		}

		$batch = [];
		$cluster = '';
		$table = '';
		$rows = array_filter(explode("\n", $request->payload));
		foreach ($rows as &$row) {
			/** @var Struct<int|string,array<string,mixed>> $struct */
			$struct = Struct::fromJson($row);
			if (isset($struct['index']['_index'])) {
				/** @var array{_index:string,_id?:string} $index */
				$index = $struct['index'];
				/** @var string $table */
				$table = $index['_index'];
				[$cluster, $table] = static::parseClaster($table);
				$index['_index'] = '%table%';
				if (!isset($struct['index']['_id'])) {
					$index['_id'] = '%id%';
				}

				$struct['index'] = $index;
				$batch["$cluster:$table"][] = $struct;
				continue;
			}

			if (!$table) {
				throw new QueryParseError('Cannot find table name');
			}

			$batch["$cluster:$table"][] = $struct;
		}
		/** @var Batch $batch */
		return $batch;
	}

	/**
	 * @param string $table
	 * @return array{0:string,1:string}
	 */
	protected static function parseClaster(string $table): array {
		$cluster = '';
		$ex = explode(':', $table);
		if (sizeof($ex) > 1) {
			$cluster = $ex[0];
			$table = $ex[1];
		}
		return [$cluster, $table];
	}
}

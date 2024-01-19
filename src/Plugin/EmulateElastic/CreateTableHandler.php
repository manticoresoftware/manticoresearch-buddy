<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class CreateTableHandler extends BaseHandlerWithClient {

	/**
	 *  Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, HTTPClient $manticoreClient): TaskResult {
			$columnExpr = static::buildColumnExpr($payload->columnInfo);
			$query = "CREATE TABLE IF NOT EXISTS {$payload->table} ($columnExpr)";
			/** @var array{error?:string} $queryResult */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();

			return TaskResult::raw($queryResult);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * @param array<string,array{properties?:array<mixed>,type?:string,fields?:array<mixed>}> $columnInfo
	 * @return string
	 */
	protected static function buildColumnExpr(array $columnInfo): string {
		$dataTypeMap = [
			'aggregate_metric' => 'json',
			'binary' => 'string',
			'boolean' => 'bool',
			'completion' => 'string',
			'date' => 'timestamp',
			'date_nanos' => 'bigint',
			'dense_vector' => 'json',
			'flattened' => 'json',
			'geo_point' => 'json',
			'geo_shape' => 'json',
			'histogram' => 'json',
			'ip' => 'string',
			'long' => 'bigint',
			'integer' => 'int',
			'short' => 'int',
			'byte' => 'int',
			'float' => 'float',
			'half_float' => 'float',
			'scaled_float' => 'float',
			'unsigned_long' => 'int',
			'object' => 'json',
			'point' => 'json',
			'integer_range' => 'json',
			'float_range' => 'json',
			'long_range' => 'json',
			'date_range' => 'json',
			'ip_range' => 'json',
			'search_as_you_type' => 'text',
			'shape' => 'json',
			'text' => 'text',
			'match_only_text' => 'text',
			'version' => 'string',
		];
		$colDefs = [];
		foreach ($columnInfo as $colName => $colInfo) {
			if (!isset($colInfo['type']) && !isset($colInfo['properties'])) {
				throw new \Exception("Data type not found for column $colName");
			}
			$dataType = isset($colInfo['type']) ? $colInfo['type'] : 'object';
			if (!isset($dataTypeMap[$dataType])) {
				throw new \Exception("Unsupported data type $dataType found in the data schema");
			}
			$colType = $dataTypeMap[$dataType];
			$dataTypeFlags = '';
			if ($colType === 'text' && isset($colInfo['fields'], $colInfo['fields']['keyword'])) {
				$dataTypeFlags .= ' indexed';
			}
			$colDefs[] = "$colName $colType" . $dataTypeFlags;
		}

		return implode(',', $colDefs);
	}

}

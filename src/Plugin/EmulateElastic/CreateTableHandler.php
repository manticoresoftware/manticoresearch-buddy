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
	 * @param array<string,array{properties?:array<mixed>,type?:string,fields?:array<mixed>,
	 * dimension?:int,method?:array<mixed>}> $columnInfo
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
			'flat_object' => 'json',
			'geo_point' => 'json',
			'geo_shape' => 'json',
			'histogram' => 'json',
			'ip' => 'string',
			'long' => 'bigint',
			'integer' => 'int',
			'keyword' => 'string',
			'knn_vector' => 'float_vector',
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
			$dataTypeOptions = match (true) {
				($dataType === 'knn_vector') => self::buildKnnFieldSettings($colInfo),
				($colType === 'text' && isset($colInfo['fields'], $colInfo['fields']['keyword'])) => ' indexed',
				default => '',
			};
			$colDefs[] = "$colName $colType" . $dataTypeOptions;
		}

		return implode(',', $colDefs);
	}

	/**
	 * @param array{dimension?:int,method?:array<mixed>} $colInfo
	 * @return string
	 */
	protected static function buildKnnFieldSettings(array $colInfo): string {
		if (!isset($colInfo['dimension'])) {
			throw new \Exception('Mandatory property `dimension` is missing for `knn_vector` type');
		}
		// default Opensearch settings for knn_vector type
		$defaults = [
			'sim' => 'l2',
			'params' => [
				'ef_construction' => 100,
				'm' => 16,
			],
		];

		$settingsExpr = " knn_type='hnsw' knn_dims='{$colInfo['dimension']}'";
		if (!isset($colInfo['method'])) {
			return $settingsExpr . " hnsw_similarity='{$defaults['sim']}' hnsw_m={$defaults['params']['m']} " .
				"hnsw_ef_construction={$defaults['params']['ef_construction']}";
		}

		/** @var array{name?:string,engine?:string,space_type?:string,parameters?:array<string,mixed>} $methodInfo */
		$methodInfo = $colInfo['method'];
		if ((isset($methodInfo['name']) && $methodInfo['name'] !== 'hnsw')
		|| (isset($methodInfo['engine']) && $methodInfo['engine'] !== 'nmslib')
		|| (isset($methodInfo['space_type'])
			&&	!in_array($methodInfo['space_type'], ['l2', 'innerproduct', 'cosinesimil']))
		) {
			throw new \Exception('Unsupported settings for `knn_vector` type found in the data schema');
		}
		$spaceTypeMapping = [
			'innerproduct' => 'ip',
			'cosinesim' => 'cosine',
			'ef_construction' => 'hnsw_ef_construction',
			'm' => 'hnsw_m',
		];
		$spaceType = isset($methodInfo['space_type'])
			? ($spaceTypeMapping[$methodInfo['space_type']] ?? $methodInfo['space_type']) : $defaults['sim'];
		$settingsExpr .= " hnsw_similarity='$spaceType'";
		if (isset($methodInfo['parameters'])) {
			foreach ($defaults['params'] as $name => $value) {
				$settingsExpr .= " {$spaceTypeMapping[$name]}='" . ($methodInfo['parameters'][$name] ?? $value) . "'";
			}
		}

		return $settingsExpr;
	}
}

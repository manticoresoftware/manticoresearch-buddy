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
 * Returns info about table field properties following Elastic's logic and response format
 */
class FieldCapsHandler extends BaseHandlerWithClient {

	const DATA_TYPE_MAP = [
		'string' => 'keyword',
		'bool' => 'boolean',
		'timestamp' => 'date',
		'float' => 'float',
		'bigint' => 'long',
		'int' => 'integer',
		'uint' => 'integer',
		'json' => 'object',
		'float_vector' => 'knn_vector',
	];

	/** @var FieldCapsHandlerHelper $helper */
	protected FieldCapsHandlerHelper $helper;

	/**
	 *  Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
		$this->helper = new FieldCapsHandlerHelper();
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (
			Payload $payload,
			HTTPClient $manticoreClient,
			FieldCapsHandlerHelper $helper
		): TaskResult {
			/** @var array<string> $requestTables */
			$requestTables = self::getRequestTables($payload, $manticoreClient);
			/** @var array<string,mixed> $request */
			$request = $payload->body ? simdjson_decode($payload->body, true) : [];
			$isEmptyRequest = !$request;
			if (!$isEmptyRequest && !isset($request['fields'])) {
				throw new \Exception('Cannot parse request');
			}
			/** @var array<string> $requestFields */
			$requestFields = ($isEmptyRequest || $request['fields'] === '*') ? [] : $request['fields'];

			/**
			 * @var array<string,array<string,array{
			 * 	aggregatable:bool,searchable:bool,type:string,indices?:array<string>
			 * }>>
			 */
			$fieldCaps = [];
			foreach ($requestTables as $table) {
				/** @var array{error?:string,0:array{data:array<array{Field:string,Type:string,Properties:string}>}} */
				$queryResult = $manticoreClient->sendRequest("DESC {$table}")->getResult();
				if (isset($queryResult['error'])) {
					throw new \Exception('Unknown error');
				}
				$helper->setTable($table);
				self::buildFieldCaps($queryResult[0]['data'], $table, $helper, $requestFields, $fieldCaps);
			}
			self::checkFieldIndices($requestTables, $fieldCaps);

			// Adding meta info to the response as well
			$fieldCapsInfo = [
				'fields' => [
					'_feature' => [
						'_feature' => [
							'aggregatable' => false,
							'searchable' => false,
							'type' => '_feature',
						],
					],
					'_field_names' => [
						'_field_names' => [
							'aggregatable' => false,
							'searchable' => true,
							'type' => '_field_names',
						],
					],
					'_id' => [
						'_id' => [
							'aggregatable' => true,
							'searchable' => true,
							'type' => '_id',
						],
					],
					'_ignored' => [
						'_ignored' => [
							'aggregatable' => false,
							'searchable' => false,
							'type' => '_ignored',
						],
					],
					'_index' => [
						'_index' => [
							'aggregatable' => true,
							'searchable' => true,
							'type' => '_index',
						],
					],
					'_seq_no' => [
						'_seq_no' => [
							'aggregatable' => false,
							'searchable' => false,
							'type' => '_seq_no',
						],
					],
					'_source' => [
						'_source' => [
							'aggregatable' => false,
							'searchable' => false,
							'type' => '_source',
						],
					],
					'_type' => [
						'_type' => [
							'aggregatable' => true,
							'searchable' => true,
							'type' => '_type',
						],
					],
					'_version' => [
						'_version' => [
							'aggregatable' => false,
							'searchable' => false,
							'type' => '_version',
						],
					],
				] + $fieldCaps,
				'indices' => $requestTables,
			];
			return TaskResult::raw($fieldCapsInfo);
		};

		$this->helper->setManticoreClient($this->manticoreClient);
		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient, $this->helper]
		)->run();
	}

	/**
	 *
	 * @param Payload $payload
	 * @param HTTPClient $manticoreClient
	 * @return array<string>
	 */
	protected static function getRequestTables(Payload $payload, HTTPClient $manticoreClient): array {
		$pathParts = explode('/', $payload->path);
		$likeCond = (sizeof($pathParts) > 1)
			? "LIKE '" . str_replace('*', '%', $pathParts[0]) . "'"
			: '';
		/** @var array{0:array{data:array<array{Table:string,Type:string}>}} */
		$queryResult = $manticoreClient->sendRequest("SHOW TABLES $likeCond")->getResult();
		$requestTables = [];
		foreach ($queryResult[0]['data'] as $tableData) {
			// Exclude Manticore tables with meta data
			if ($tableData['Type'] === 'distributed' || in_array(substr($tableData['Table'], 0, 1), ['_', '.'])) {
				continue;
			}
			$requestTables[] = $tableData['Table'];
		}

		return $requestTables;
	}

	/**
	 * If field is contained in all request tables we omit the 'indices' field as Elastic does
	 *
	 * @param array<string> $requestTables
	 * @param array<string,array<string,array{
	 * 	aggregatable:bool,searchable:bool,type:string,indices?:array<string>
	 * }>> $fieldCaps
	 * @return void
	 */
	protected static function checkFieldIndices(array $requestTables, array &$fieldCaps): void {
		foreach ($fieldCaps as $fieldName => $fieldInfo) {
			foreach ($fieldInfo as $fieldType => $fieldProps) {
				if (!(isset($fieldProps['indices']) && $fieldProps['indices'] === $requestTables)) {
					continue;
				}
				unset($fieldCaps[$fieldName][$fieldType]['indices']);
			}
		}
	}

	/**
	 * @param string $table
	 * @param string $fieldType
	 * @param bool $isAggregatable
	 * @param bool $isSearchable
	 * @return array<string,mixed>
	 */
	protected static function buildFieldInfo(
		string $table,
		string $fieldType,
		bool $isAggregatable,
		bool $isSearchable,
	): array {
		return [
			'aggregatable' => $isAggregatable,
			'searchable' => $isSearchable,
			'type' => $fieldType,
			'indices' => [$table],
		];
	}

	/**
	 * @param array<array{Field?:string,Type:string,Properties:string}> $tableFieldsInfo
	 * @param string $table
	 * @param FieldCapsHandlerHelper $helper
	 * @param array<string> $requestFields
	 * @param array<string,array<string,array<string,mixed>>> $fieldCaps
	 * @return void
	 */
	protected static function buildFieldCaps(
		array $tableFieldsInfo,
		string $table,
		FieldCapsHandlerHelper $helper,
		array $requestFields,
		array &$fieldCaps
	): void {
		foreach ($tableFieldsInfo as $fieldData) {
			if (!array_key_exists('Field', $fieldData)) {
				continue;
			}
			$fieldName = $fieldData['Field'];
			if ($requestFields && !in_array($fieldName, $requestFields)) {
				continue;
			}
			$fieldType = array_key_exists($fieldData['Type'], self::DATA_TYPE_MAP)
				? self::DATA_TYPE_MAP[$fieldData['Type']] : $fieldData['Type'];
			$isSearchable = true;
			$isAggregatable = !in_array($fieldType, ['text', 'object']);
			$isIndexed = str_contains($fieldData['Properties'], 'indexed');
			// Extra processing of json/objects fields to get info of their properties too
			if ($fieldType === 'object') {
				$isSearchable = false;
				$subFieldInfo = $helper->getJsonSubFieldsInfo($fieldName, $isIndexed);
				foreach ($subFieldInfo as $subFieldName => $subFieldType) {
					$isSubAggregatable = $isSubSearchable = ($subFieldType !== 'object');
					self::updateFieldCaps(
						$fieldCaps,
						$table,
						$subFieldName,
						$subFieldType,
						true,
						$isSubAggregatable,
						$isSubSearchable,
					);
				}
			}

			self::updateFieldCaps($fieldCaps, $table, $fieldName, $fieldType, false, $isAggregatable, $isSearchable);
		}
	}

	/**
	 * @param array<string,array<string,array<string,mixed>>> $fieldCaps
	 * @param string $table
	 * @param string $fieldName
	 * @param string $fieldType
	 * @param bool $isJsonSubField
	 * @param bool $isAggregatable
	 * @param bool $isSearchable
	 * @return void
	 */
	protected static function updateFieldCaps(
		array &$fieldCaps,
		string $table,
		string $fieldName,
		string $fieldType,
		bool $isJsonSubField,
		bool $isAggregatable,
		bool $isSearchable,
	): void {
		$fieldInfo = self::buildFieldInfo($table, $fieldType, $isAggregatable, $isSearchable);
		if (!isset($fieldCaps[$fieldName])) {
			$fieldCaps[$fieldName] = [
				$fieldType => $fieldInfo,
			];
			return;
		}
		if (!isset($fieldCaps[$fieldName][$fieldType])) {
			$fieldCaps[$fieldName] = [$fieldType => $fieldInfo];
			return;
		}
		if ($isJsonSubField) {
			return;
		}
		/** @var array<mixed> $indices */
		$indices = $fieldCaps[$fieldName][$fieldType]['indices'];
		$indices[] = $table;
		$fieldCaps[$fieldName][$fieldType]['indices'] = $indices;
	}
}

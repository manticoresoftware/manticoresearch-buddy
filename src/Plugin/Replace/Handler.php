<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Replace;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class Handler extends BaseHandlerWithClient {
	// Todo expose this types to Buddy-core
	const TYPE_INT = 'uint';
	const TYPE_BIGINT = 'bigint';
	const TYPE_TIMESTAMP = 'timestamp';
	const TYPE_BOOL = 'bool';
	const TYPE_FLOAT = 'float';
	const TYPE_TEXT = 'text';
	const TYPE_STRING = 'string';
	const TYPE_JSON = 'json';
	const TYPE_MVA = 'mva';
	const TYPE_MVA64 = 'multi64';
	const TYPE_FLOAT_VECTOR = 'float_vector';

	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, Client $client): TaskResult {
			$fields = self::getFields($client, $payload->table);
			static::checkStoredFields($fields);

			$baseValues = static::getRecordValues($client, $payload, $fields);
			if ($payload->type === RequestFormat::JSON->value) {
				$payload->set = self::morphValuesByFieldType($payload->set, $fields);
			}

			$result = $client->sendRequest(
				static::buildQuery($payload->table, array_merge($baseValues, $payload->set))
			);

			if ($result->getError()) {
				throw ManticoreSearchResponseError::create($result->getError());
			}

			if ($payload->type === RequestFormat::JSON->value) {
				return TaskResult::raw(['_index' => $payload->table, 'updated' => 1]);
			}
			return TaskResult::none();
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * @param Client $manticoreClient
	 * @param string $table
	 * @return array<int|string, array{type:string, properties:string}>
	 * @throws ManticoreSearchClientError
	 */
	private static function getFields(Client $manticoreClient, string $table): array {
		$descResult = $manticoreClient
			->sendRequest('DESC ' . $table)
			->getResult();

		if (is_array($descResult[0])) {
			$fields = [];
			/** @var array{Type:string, Properties:string, Field:string} $field */
			foreach ($descResult[0]['data'] as $field) {
				$fields[$field['Field']] = ['type' => $field['Type'], 'properties' => $field['Properties']];
			}

			return $fields;
		}

		throw ManticoreSearchClientError::create('Table hasn\'t fields');
	}


	/**
	 * @param array<int|string, array{type:string, properties:string}> $fields
	 * @return void
	 * @throws GenericError
	 */
	private static function checkStoredFields(array $fields): void {
		foreach ($fields as $fieldName => $fieldSettings) {
			if ($fieldSettings['type'] !== 'text'
				|| str_contains($fieldSettings['properties'], 'stored')) {
				continue;
			}

			ManticoreSearchResponseError::throw(
				'Field ' . $fieldName
				. ' doesn\'t have stored property. Replace query can\'t be performed'
			);
		}
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @param array<int|string, array{type:string, properties:string}> $fields
	 * @return array<string, bool|float|int|string>
	 * @throws ManticoreSearchClientError
	 */
	private static function getRecordValues(Client $manticoreClient, Payload $payload, array $fields): array {
		$sql = 'SELECT * FROM ' . $payload->table . ' WHERE id = ' . $payload->id;

		/** @var array<int, array<string, array<int, array<string, string>>>> $records */
		$records = $manticoreClient
			->sendRequest($sql)
			->getResult();

		if (isset($records[0]['data'][0])) {
			return self::morphValuesByFieldType($records[0]['data'][0], $fields);
		}

		return ['id' => $payload->id];
	}

	/**
	 * @param array<string, bool|float|int|string|array<int, string>> $records
	 * @param array<int|string, array{type:string, properties:string}> $fields
	 * @return array<string, bool|float|int|string>
	 */
	private static function morphValuesByFieldType(array $records, array $fields): array {

		foreach ($records as $fieldName => $fieldValue) {
			$records[$fieldName] = match ($fields[$fieldName]['type']) {
				self::TYPE_INT, self::TYPE_BIGINT, self::TYPE_TIMESTAMP => (int)$fieldValue,
				self::TYPE_BOOL => (bool)$fieldValue,
				self::TYPE_FLOAT => (float)$fieldValue,
				self::TYPE_TEXT, self::TYPE_STRING, self::TYPE_JSON =>
					"'" . (is_array($fieldValue) ? json_encode($fieldValue) : $fieldValue)  . "'",
				self::TYPE_MVA, self::TYPE_MVA64, self::TYPE_FLOAT_VECTOR =>
					'(' . (is_array($fieldValue) ? implode(',', $fieldValue) : $fieldValue) . ')',
				default => $fieldValue
			};
		}

		/** @var array<string, bool|float|int|string> */
		return $records;
	}

	/**
	 * @param string $tableName
	 * @param array<string, bool|float|int|string> $set
	 * @return string
	 */
	private static function buildQuery(string $tableName, array $set): string {
		return 'REPLACE INTO `' . $tableName . '` (' . implode(',', array_keys($set)) . ') ' .
			'VALUES (' . implode(',', array_values($set)) . ')';
	}


}

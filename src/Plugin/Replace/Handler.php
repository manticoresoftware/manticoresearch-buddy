<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Replace;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class Handler extends BaseHandlerWithClient
{
	/**
	 * Initialize the executor
	 *
	 * @param  Payload  $payload
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
			$client->sendRequest(static::buildQuery($payload->table, array_merge($baseValues, $payload->set)));

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
	 * @param  Client  $manticoreClient
	 * @param  string  $table
	 * @return array <int, array<string, string>>
	 * @throws ManticoreSearchClientError
	 */
	private static function getFields(Client $manticoreClient, string $table): array {
		$descResult = $manticoreClient
			->sendRequest('DESC '.$table)
			->getResult();

		if (is_array($descResult[0])) {
			return $descResult[0]['data'];
		}

		return [];
	}


	/**
	 * @param  array<int, array<string, string>>  $fields
	 * @return void
	 * @throws \Manticoresearch\Buddy\Core\Error\GenericError
	 */
	private static function checkStoredFields(array $fields): void {
		foreach ($fields as $field) {
			if ($field['Type'] !== 'text'
				|| str_contains($field['Properties'], 'stored')) {
				continue;
			}

			ManticoreSearchResponseError::throw(
				'Field '.$field['Field']
				.' doesn\'t have stored property. Replace query can\'t be performed'
			);
		}
	}

	/**
	 * @param  Client  $manticoreClient
	 * @param  Payload  $payload
	 * @param  array<int, array<string, string>>  $fields
	 * @return array<string, string|int>
	 * @throws ManticoreSearchClientError
	 */
	private static function getRecordValues(Client $manticoreClient, Payload $payload, array $fields): array {
		$sql = 'SELECT * FROM '.$payload->table.' WHERE id = '.$payload->id;

		$records = $manticoreClient
			->sendRequest($sql)
			->getResult();

		$mvaFields = [];

		foreach ($fields as $key) {
			if ($key['Type'] !== 'mva') {
				continue;
			}

			$mvaFields[] = $key['Field'];
		}

		if (is_array($records) && !empty($records[0]['data'][0])) {
			// We need migrate MVA values to correct syntax for replace call
			if ($mvaFields !== []) {
				foreach ($mvaFields as $field) {
					$records[0]['data'][0][$field] = '('.$records[0]['data'][0][$field].')';
				}
			}

			return $records[0]['data'][0];
		}

		return ['id' => $payload->id];
	}


	/**
	 * @param  string  $tableName
	 * @param  array<string, string|int|array<string>> $set
	 * @return string
	 */
	private static function buildQuery(string $tableName, array $set): string {
		$keys = [];
		$values = [];
		foreach ($set as $key => $value) {
			$keys[] = $key;

			if (is_numeric($value)) {
				$values[] = $value;
			} elseif (isset($value[0]) && is_string($value) && $value[0] === '(') {
				$values[] = $value;
			} elseif (is_array($value)) {
				$values[] = '('.implode(',', $value).')';
			} else {
				$values[] = "'".$value."'";
			}
		}

		return 'REPLACE INTO `'.$tableName.'` ('.implode(',', $keys).') '.
			'VALUES ('.implode(',', $values).')';
	}


}

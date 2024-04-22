<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/


namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers;

use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

/**
 * @template T of array
 */
abstract class BaseGetHandler extends BaseHandlerWithClient {

	/**
	 * Initialize the executor
	 *
	 * @param Payload<T> $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request
	 * @return Task
	 */
	public function run(): Task {

		$name = $this->getName($this->payload);
		$type = $this->getType();
		$fields = $this->getFields();
		$tableName = $this->getTableName();

		/**
		 * @param string $name
		 * @param string $type
		 * @param array<string> $fields
		 * @param string $tableName
		 * @param Client $manticoreClient
		 * @return TaskResult
		 * @throws ManticoreSearchClientError
		 */
		$taskFn = static function (
			string $name,
			string $type,
			array  $fields,
			string $tableName,
			Client $manticoreClient
		): TaskResult {

			if (!$manticoreClient->hasTable($tableName)) {
				return TaskResult::none();
			}

			$fields[] = 'original_query';
			$stringFields = implode(',', $fields);
			$sql = /** @lang manticore */
				"SELECT $stringFields FROM $tableName WHERE match('@name \"$name\"') LIMIT 1";
			$rawResult = $manticoreClient->sendRequest($sql);
			if ($rawResult->hasError()) {
				throw ManticoreSearchClientError::create((string)$rawResult->getError());
			}

			$rawResult = $rawResult->getResult();

			if (is_array($rawResult[0]) && empty($rawResult[0]['data'])) {
				return TaskResult::none();
			}

			$formattedResult = static::formatResult($rawResult[0]['data'][0]['original_query']);

			$resultData = [
				$type => $name,
				'Create Table' => $formattedResult,
			];

			foreach ($fields as $field) {
				if ($field === 'original_query') {
					continue;
				}
				$resultData[$field] = $rawResult[0]['data'][0][$field];
			}

			return self::prepareTaskResult($resultData, $type, $fields);
		};

		return Task::create(
			$taskFn,
			[$name, $type, $fields, $tableName, $this->manticoreClient]
		)->run();
	}

	/**
	 * @param array<string, string> $resultData
	 * @param string $type
	 * @param array<string> $fields
	 * @return TaskResult
	 */
	private static function prepareTaskResult(array $resultData, string $type, array $fields): TaskResult {
		$taskResult = TaskResult::withData([$resultData])
			->column($type, Column::String)
			->column('Create Table', Column::String);

		foreach ($fields as $field) {
			if ($field === 'original_query') {
				continue;
			}
			$taskResult = $taskResult->column($field, Column::String);
		}

		return $taskResult;
	}

	/**
	 * @return array<string>
	 */
	abstract protected function getFields(): array;

	/**
* @param Payload<T> $payload
* @return string
	 */
	abstract protected function getName(Payload $payload): string;

	abstract protected function getTableName(): string;

	abstract protected function getType(): string;

	abstract protected static function formatResult(string $query): string;


}

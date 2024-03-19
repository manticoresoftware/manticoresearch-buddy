<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers;

use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

abstract class BaseGetHandler extends BaseHandlerWithClient
{

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
		 * @param array $fields
		 * @param string $tableName
		 * @param Client $manticoreClient
		 * @return TaskResult
		 * @throws ManticoreSearchClientError
		 */
		$taskFn = static function (string $name, string $type, array $fields, string $tableName, Client $manticoreClient): TaskResult {

			if (!$manticoreClient->hasTable($tableName)) {
				return TaskResult::none();
			}

			$fields[] = 'original_query';
			$stringFields = implode(',', $fields);
			$sql = /** @lang manticore */
				"SELECT $stringFields FROM $tableName WHERE match('@name \"$name\"') LIMIT 1";
			$rawResult = $manticoreClient->sendRequest($sql);
			if ($rawResult->hasError()) {
				throw ManticoreSearchClientError::create($rawResult->getError());
			}

			$rawResult = $rawResult->getResult();

			if (empty($rawResult[0]['data'])) {
				return TaskResult::none();
			}

			$formattedResult = static::formatResult($rawResult[0]['data'][0]['original_query']);

			$result = [
				$type => $name,
				'Create Table' => $formattedResult,
			];

			foreach ($fields as $field){
				if ($field === 'original_query'){
					continue;
				}
				$result[$field] = $rawResult[0]['data'][0][$field];
			}

			$taskResult = TaskResult::withData([$result])
				->column($type, Column::String)
				->column('Create Table', Column::String);

			foreach ($fields as $field){
				if ($field === 'original_query'){
					continue;
				}
				$taskResult = $taskResult->column($field, Column::String);
			}

			return $taskResult;
		};

		return Task::create(
			$taskFn,
			[$name, $type, $fields, $tableName, $this->manticoreClient]
		)->run();
	}

	abstract protected function getFields();

	abstract protected function getName(Payload $payload): string;

	abstract protected function getTableName(): string;

	abstract protected function getType(): string;

	abstract protected static function formatResult(string $query): string;


}

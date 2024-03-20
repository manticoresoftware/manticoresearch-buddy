<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers;

use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Base\Plugin\Queue\QueueProcess;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

abstract class BaseDropHandler extends BaseHandlerWithClient
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
		$tableName = $this->getTableName();

		/**
		 * @param string $name
		 * @param string $tableName
		 * @param Client $manticoreClient
		 * @return TaskResult
		 * @throws ManticoreSearchClientError
		 */
		$taskFn = static function (string $name, string $tableName, Client $manticoreClient): TaskResult {

			if (!$manticoreClient->hasTable($tableName)) {
				return TaskResult::none();
			}

			$sql = /** @lang Manticore */
				"SELECT * FROM $tableName WHERE match('@name \"$name\"')";


			$result = $manticoreClient->sendRequest($sql);

			if ($result->hasError()) {
				throw ManticoreSearchClientError::create($result->getError());
			}

			$removed = 0;
			foreach ($result->getResult()[0]['data'] as $sourceRow) {
				QueueProcess::getInstance()->stopWorkerByName($sourceRow['full_name']);
				self::removeSourceRowData($sourceRow, $manticoreClient);
				$removed ++;
			}

			return TaskResult::withTotal($removed);
		};

		return Task::create(
			$taskFn,
			[$name, $tableName, $this->manticoreClient]
		)->run();
	}


	/**
	 * @throws ManticoreSearchClientError
	 */
	protected static function removeSourceRowData(array $sourceRow, Client $client): void {

		$queries = [
			/** @lang Manticore */
			"DROP TABLE {$sourceRow['buffer_table']}",
			/** @lang Manticore */
			"UPDATE _views SET suspended=1 WHERE match('@source_name \"{$sourceRow['full_name']}\"')",
			/** @lang Manticore */
			"DELETE FROM _sources WHERE id = {$sourceRow['id']}",
		];

		foreach ($queries as $query) {
			$request = $client->sendRequest($query);
			if ($request->hasError()) {
				throw ManticoreSearchClientError::create($request->getError());
			}
		}
	}

	abstract protected function getName(Payload $payload): string;

	abstract protected function getTableName(): string;

}

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
		$tableName = $this->getTableName();

		/**
		 * @param string $name
		 * @param string $type
		 * @param string $tableName
		 * @param Client $manticoreClient
		 * @return TaskResult
		 * @throws ManticoreSearchClientError
		 */
		$taskFn = static function (string $name, string $type, string $tableName, Client $manticoreClient): TaskResult {

			if (!$manticoreClient->hasTable($tableName)) {
				return TaskResult::none();
			}


			$sql = /** @lang manticore */
				"SELECT original_query FROM $tableName WHERE match('@name \"$name\"') LIMIT 1";
			$result = $manticoreClient->sendRequest($sql);
			if ($result->hasError()) {
				throw ManticoreSearchClientError::create($result->getError());
			}

			$result = $result->getResult();

			if (empty($result[0]['data'])) {
				return TaskResult::none();
			}

			$formattedResult = static::formatResult($result[0]['data'][0]['original_query']);

			return TaskResult::withData(
				[[
					$type => $name,
					'Create Table' => $formattedResult,
				]]
			)->column($type, Column::String)
				->column('Create Table', Column::String);
		};

		return Task::create(
			$taskFn,
			[$name, $type, $tableName, $this->manticoreClient]
		)->run();
	}

	protected function getName(Payload $payload): string {
		return $payload->parsedPayload['SHOW'][1]['no_quotes']['parts'][0];
	}

	abstract protected function getTableName(): string;

	abstract protected function getType(): string;

	abstract protected static function formatResult(string $query): string;


}

<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers;

use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
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

			$sql = /** @lang manticore */
				"DELETE FROM $tableName WHERE match('@name \"$name\"')";
			$result = $manticoreClient->sendRequest($sql);
			if ($result->hasError()) {
				throw ManticoreSearchClientError::create($result->getError());
			}


			return TaskResult::raw($result->getResult());
		};

		return Task::create(
			$taskFn,
			[$name, $tableName, $this->manticoreClient]
		)->run();
	}

	protected function getName(Payload $payload): string {
		return $payload->parsedPayload['DROP'][1]['no_quotes']['parts'][0];
	}

	abstract protected function getTableName(): string;

}

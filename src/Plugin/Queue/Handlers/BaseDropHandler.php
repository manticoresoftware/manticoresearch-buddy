<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers;

use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
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
		 * @return TaskResult
		 */
		$taskFn = function (string $name, string $tableName): TaskResult {
			$manticoreClient = $this->manticoreClient;
			if (!$manticoreClient->hasTable($tableName)) {
				return TaskResult::none();
			}

			return TaskResult::withTotal($this->processDrop($name, $tableName, $manticoreClient));
		};

		return Task::create(
			$taskFn,
			[$name, $tableName]
		)->run();
	}


	abstract protected function processDrop(string $name, string $tableName): int;

	abstract protected function getName(Payload $payload): string;

	abstract protected function getTableName(): string;

}

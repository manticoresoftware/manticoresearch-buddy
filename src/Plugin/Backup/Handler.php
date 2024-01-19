<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Backup;

use Manticoresearch\Backup\Lib\FileStorage;
use Manticoresearch\Backup\Lib\ManticoreBackup;
use Manticoresearch\Backup\Lib\ManticoreClient;
use Manticoresearch\Backup\Lib\ManticoreConfig;
use Manticoresearch\Buddy\Core\Plugin\BaseHandler;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

/**
 * This is the class to handle BACKUP ... SQL command
 */
class Handler extends BaseHandler {
  /**
   *  Initialize the executor
   *
   * @param Payload $payload
   * @return void
   */
	public function __construct(protected Payload $payload) {
	}

  /**
   * Process the request and return self for chaining
   *
   * @return Task
   */
	public function run(): Task {
		// We run in a thread anyway but in case if we need blocking
		// We just waiting for a thread to be done
		$isAsync = $this->payload->options['async'] ?? false;
		$task = Task::create(
			static function (string $args): TaskResult {
				/** @var Payload $payload */
				/** @phpstan-ignore-next-line */
				[$payload] = unserialize($args);
				$config = new ManticoreConfig($payload->configPath);
				$client = new ManticoreClient($config);
				$storage = new FileStorage(
					$payload->path,
					$payload->options['compress'] ?? false
				);
				ManticoreBackup::run('store', [$client, $storage, $payload->tables]);
				;
				return TaskResult::withRow(
					[
						'Path' => $storage->getBackupPaths()['root'],
					]
				)->column('Path', Column::String);
			},
			[serialize([$this->payload])]
		);
		if ($isAsync) {
			$task->defer();
		}
		return $task->run();
	}

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return [];
	}
}

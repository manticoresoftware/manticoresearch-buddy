<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Backup\Lib\FileStorage;
use Manticoresearch\Backup\Lib\ManticoreBackup;
use Manticoresearch\Backup\Lib\ManticoreClient;
use Manticoresearch\Backup\Lib\ManticoreConfig;
use Manticoresearch\Backup\Lib\Searchd;
use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Network\Response;

/**
 * This is the class to handle BACKUP ... SQL command
 */
class BackupExecutor implements CommandExecutorInterface {
  /**
   *  Initialize the executor
   *
   * @param BackupRequest $request
   * @return void
   */
	public function __construct(protected BackupRequest $request) {
	}

  /**
   * Process the request and return self for chaining
   *
   * @return Task
   */
	public function run(): Task {
		// We run in a thread anyway but in case if we need blocking
		// We just waiting for a thread to be done
		$isAsync = $this->request->options['async'] ?? false;
		$method = $isAsync ? 'defer' : 'create';
		$Task = Task::$method(
			function (BackupRequest $request): Response {
				Searchd::init();

				$config = new ManticoreConfig($request->configPath);
				$client = new ManticoreClient($config);
				$storage = new FileStorage(
					$request->path,
					$request->options['compress'] ?? false
				);
				ManticoreBackup::store($client, $storage, $request->tables);
				return Response::none();
			}, [$this->request]
		);

		return $Task->run();
	}
}

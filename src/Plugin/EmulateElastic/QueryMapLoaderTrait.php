<?php declare(strict_types=1);

/*
  Copyright (c) 2024-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

trait QueryMapLoaderTrait {

	/** @var array<mixed> $queryMap */
	protected static $queryMap = null;

	/**
	 * @param string $mapName
	 * @return void
	 */
	protected static function initQueryMap(string $mapName): void {
		if (static::$queryMap !== null) {
			return;
		}
		$queryMapFilePattern = __DIR__ . '/QueryMap/%MAP_NAME%.php';
		/** @var array<mixed> $queryMap */
		$queryMap = include (string)str_replace('%MAP_NAME%', $mapName, $queryMapFilePattern);
		static::$queryMap = $queryMap;
	}

	/**
	 * @param string $query
	 * @param ?\Closure $preprocessor
	 * @return Task
	 * @throws RuntimeException
	 */
	protected static function getResponseByQuery(string $query, ?\Closure $preprocessor = null): Task {
		if (!isset(self::$queryMap[$query])) {
			throw new \Exception("Unknown request path passed: $query");
		}

		/** @var array<mixed> $resp */
		$resp = self::$queryMap[$query];
		if ($preprocessor !== null) {
			$preprocessor($resp);
		}
		$taskFn = static function (array $resp): TaskResult {
			return TaskResult::raw($resp);
		};

		return Task::create($taskFn, [$resp])->run();
	}
}

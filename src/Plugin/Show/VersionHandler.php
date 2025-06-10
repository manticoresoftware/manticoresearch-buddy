<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Show;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class VersionHandler extends BaseHandlerWithClient
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
		$taskFn = static function (Client $manticoreClient): TaskResult {
			$query = "SHOW STATUS like 'version'";

			return TaskResult::withData(self::parseVersions($manticoreClient->sendRequest($query)->getResult()))
				->column('Component', Column::String)
				->column('Version', Column::String);
		};

		return Task::create(
			$taskFn, [$this->manticoreClient]
		)->run();
	}

	/**
	 * @param Struct<int|string, mixed> $result
	 * @return array<int<0, max>, array<string, string>>
	 */
	private static function parseVersions(Struct $result):array {
		$versions = [];
		if (is_array($result[0]) && isset($result[0]['data'][0]['Value'])) {
			$value = $result[0]['data'][0]['Value'];

			$splittedVersions = explode('(', $value);

			foreach ($splittedVersions as $n => $version) {
				$version = trim($version);

				if ($version[mb_strlen($version) - 1] === ')') {
					$version = substr($version, 0, -1);
				}

				$exploded = explode(' ', $version);
				$component = $n > 0 ? ucfirst($exploded[0]) : 'Daemon';

				$versions[] = ['Component' => $component, 'Version' => $version];
			}
		}

		return $versions;
	}
}

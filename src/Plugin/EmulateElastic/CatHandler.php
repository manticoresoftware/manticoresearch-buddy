<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Base\Lib\QueryProcessor;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

use RuntimeException;

/**
 * Handling requests to extract and build info on Kibana entities, like templates, dashboards, etc.
 */
class CatHandler extends BaseHandlerWithClient {

	const CAT_ENTITIES = ['templates', 'plugins'];

	/**
	 *  Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, HTTPClient $manticoreClient): TaskResult {
			$pathParts = explode('/', $payload->path);
			if (!isset($pathParts[1], $pathParts[2])
			&& !in_array($pathParts[1], self::CAT_ENTITIES) && !str_ends_with($pathParts[1], '*')) {
				throw new \Exception('Cannot parse request');
			}
			$entityTable = "_{$pathParts[1]}";
			$entityNamePattern = $pathParts[2];

			$query = "SELECT * FROM {$entityTable} WHERE MATCH('{$entityNamePattern}')";
			/** @var array{0:array{data?:array<array{name:string,patterns:string,content:string}>}} $queryResult */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (!isset($queryResult[0]['data']) || !$queryResult[0]['data']) {
				return TaskResult::raw([]);
			}

			$catInfo = [];
			foreach ($queryResult[0]['data'] as $entityInfo) {
				$catInfo[] = match ($entityTable) {
					'_templates' => self::buildCatTemplateRow($entityInfo),
					default => [],
				};
			}

			return TaskResult::raw($catInfo);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 *
	 * @param array{name:string,patterns:string,content:string} $entityInfo
	 * @return array<string,mixed>
	 */
	private static function buildCatTemplateRow(array $entityInfo): array {
		return [
			'name' => $entityInfo['name'],
			'order' => 0,
			'index_patterns' => simdjson_decode($entityInfo['patterns'], true),
		] + simdjson_decode($entityInfo['content'], true);
	}

}

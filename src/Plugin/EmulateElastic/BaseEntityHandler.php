<?php declare(strict_types=1);

/*
  Copyright (c) 2024-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;

abstract class BaseEntityHandler extends BaseHandlerWithClient {

	const ALIAS_TABLE = '_aliases';
	const ENTITY_TABLE = '_kibana_entities';

	/**
	 *  Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * @param string $path
	 * @param HTTPClient $manticoreClient
	 * @return array{0:string,1:string,2:string}
	 * @throws \Exception
	 */
	protected static function getEntityInfo(string $path, HTTPClient $manticoreClient): array {
		$pathParts = explode('/', $path);
		if (sizeof($pathParts) !== 3) {
			throw new \Exception('Cannot parse request');
		}

		$entityIndexAlias = urldecode($pathParts[0]);
		$entityIndex = self::getEntityIndex($entityIndexAlias, $manticoreClient);
		$entityId = urldecode(end($pathParts));

		return [$entityId, $entityIndex, $entityIndexAlias];
	}

	/**
	 * @param string $indexAlias
	 * @param HTTPClient $manticoreClient
	 * @return string
	 */
	protected static function getEntityIndex(string $indexAlias, HTTPClient $manticoreClient): string {
		$query = 'SELECT index FROM ' . self::ALIAS_TABLE . " WHERE alias='{$indexAlias}'";
		/** @var array{0:array{data?:array<array{index:string}>}} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (isset($queryResult[0]['data']) && $queryResult[0]['data']) {
			$entityIndex = $queryResult[0]['data'][0]['index'];
		} else {
			$entityIndex = $indexAlias;
		}

		return $entityIndex;
	}

	/**
	 * @param string $query
	 * @param HTTPClient $manticoreClient
	 * @param string $queryType
	 * @return void
	 * @throws \Exception
	 */
	protected static function executeQuery(string $query, HTTPClient $manticoreClient, string $queryType): void {
		/** @var array{error?:string,0:array{data?:array<mixed>}} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (isset($queryResult['error'])) {
			throw new \Exception("Unknown error on Kibana {$queryType}");
		}
	}

	/**
	 * @param string $entityId
	 * @param string $entityIndex
	 * @param string $result
	 * @return array<mixed>
	 */
	protected static function buildResponse(string $entityId, string $entityIndex, string $result): array {
		return [
			'_id' => $entityId,
			'_index' => $entityIndex,
			'_primary_term' => 1,
			'_seq_no' => 0,
			'_shards' => [
				'failed' => 0,
				'successful' => 1,
				'total' => 1,
			],
			'_type' => '_doc',
			'_version' => 1,
			'result' => $result,
		];
	}

}

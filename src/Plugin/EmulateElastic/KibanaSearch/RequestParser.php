<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\BaseNode;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Factory;

/**
 *  Parses search request from Kibana splitting it into separate 'node' objects
 */
class RequestParser {

	// The root-level keys of Kibana's request object we need to process
	const HANDLED_ROOT_KEYS = ['aggs', 'query'];

	// The types of sub-nodes in Kibana's request object
	const NAME_NODE_TYPE = 1; // node with a name of aggregation operation, corresponds to a 'node' object we build
	const AGG_NODE_TYPE = 2; // parent node of some Kibana aggregation
	const FILTER_WRAPPER_NODE_TYPE = 3; // wrapper node
	const AGG_WRAPPER_NODE_TYPE = 4; // wrapper node

	/**
	 * @param array<mixed> $request
	 * @param Factory $nodeFactory
	 */
	public function __construct(protected array $request, protected Factory $nodeFactory) {
	}

	/**
	 * @return array<BaseNode>
	 */
	public function parse(): array {
		return $this->parseSubtree($this->request);
	}

	/**
	 * @param array<mixed> $subtree
	 * @param string|int $parentKey
	 * @param string|int $aggKey
	 * @return array<BaseNode>
	 */
	protected function parseSubtree(array $subtree, string|int $parentKey = '', string|int $aggKey = ''): array {
		$parsedNodes = [];
		$keys = array_keys($subtree);
		// The order of keys in Kibana's request object doesn't correspond to the order they're processed in by Elastic
		// so we need to rearrange them first
		if (sizeof($keys) > 1) {
			$keys = $this->sortKeysByParsePriority($parentKey, $keys);
		}

		foreach ($keys as $key) {
			/** @var array{
			 * field:string,
			 * size:int,
			 * order:array<string,string>,
			 * min_doc_count?:int,
			 * extended_bounds?:array{0:int,1:int},
			 * interval:int,
			 * calendar_interval?:string,
			 * fixed_interval?:string,
			 * time_zone:string,
			 * ranges:array<int,array{from?:string|float,to?:string|float}>,
			 * bool:array{filter:array<mixed>,should:array<mixed>,must:array<mixed>,must_not:array<mixed>}
			 * } $nextSubtree
			 */
			$nextSubtree = $subtree[$key];
			if (!is_array($nextSubtree)) {
				throw new \Exception('Unknown parse error');
			}
			$keyType = $this->determineKeyType($key, $parentKey);
			if ($keyType === self::NAME_NODE_TYPE) {
				$parsedNodes[] = $this->nodeFactory->createNode($nextSubtree, (string)$key, (string)$aggKey);
				continue;
			}
			if ($keyType === self::AGG_NODE_TYPE) {
				$aggKey = $key;
			}
			$parsedNodes = [
				...$parsedNodes,
				...$this->parseSubtree($nextSubtree, $key, $aggKey),
			];
		}

		return $parsedNodes;
	}

	/**
	 * @param string|int $key
	 * @param string|int $parentKey
	 * @return int
	 */
	protected function determineKeyType(string|int $key, string|int $parentKey): int {
		return match (true) {
			($key === 'aggs') => self::AGG_WRAPPER_NODE_TYPE,
			($key === 'filters') => self::FILTER_WRAPPER_NODE_TYPE,
			($parentKey === 'aggs') => self::AGG_NODE_TYPE,
			default => self::NAME_NODE_TYPE
		};
	}

	/**
	 * Sorting the keys of Kibana's request object following Elastic's logic
	 *
	 * string|int $parentKey
	 * @param array<string|int> $keys
	 * @return array<string|int>
	 */
	protected function sortKeysByParsePriority(string|int $parentKey, array $keys): array {
		switch ($parentKey) {
			case '':
				return static::HANDLED_ROOT_KEYS;
			case 'aggs':
				usort($keys, fn ($k1) => is_numeric($k1) ? 1 : -1);
				return $keys;
			default:
				usort($keys, fn ($k1) => ($k1 === 'aggs') ? 1 : -1);
				return $keys;
		}
	}
}

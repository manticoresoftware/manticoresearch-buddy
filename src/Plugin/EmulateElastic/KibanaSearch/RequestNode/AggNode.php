<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\SphinxQLRequest;

/**
 *  Node of Kibana search request representing Kibana aggregation
 */
abstract class AggNode extends BaseNode {

	/** @var string $countField */
	protected string $countField = '';
	/** @var string $field */
	protected string $field = '';

	/** @return string */
	public function getField(): string {
		return $this->field;
	}

	/**
	 * @param SphinxQLRequest $request
	 * @return static
	 */
	public function setRequest(SphinxQLRequest $request): static {
		parent::setRequest($request);
		$this->countField = $request->getCountField();
		return $this;
	}

	/**
	 * @param array<string,mixed> $responseNode
	 * @param array<string,mixed> $extraData
	 * @return void
	 */
	protected function makeResponseBucketsIfNotExist(array &$responseNode, array $extraData = []): void {
		// If a bucket for the given key exists already, skip
		if (array_key_exists($this->key, $responseNode)
			&& (is_array($responseNode[$this->key]) && array_key_exists('buckets', $responseNode[$this->key]))
		) {
			return;
		}
		if (!array_key_exists($this->key, $responseNode)) {
			$responseNode[$this->key] = [];
		}
		/** @var array<mixed> $subNode */
		$subNode = &$responseNode[$this->key];
		$subNode['buckets'] = [];
		if (!$extraData) {
			return;
		}
		$responseNode[$this->key] += $extraData;
	}

	/**
	 * @param array<int,array<string,mixed>> $buckets
	 * @param string $key
	 * @param mixed $val
	 * @return int
	 */
	public function findBucket(array $buckets, string $key, mixed $val): int {
		foreach ($buckets as $i => $bucket) {
			if (array_key_exists($key, $bucket) && $bucket[$key] === $val) {
				return $i;
			}
		}
		return -1;
	}

	/**
	 * @param array<string,mixed> $responseNode
	 * @param array<string, mixed> $dataRow
	 * @param string $nextNodeKey
	 * @return array<string|int>|false
	 */
	abstract public function fillInResponse(array &$responseNode, array $dataRow, string $nextNodeKey): array|false;
}

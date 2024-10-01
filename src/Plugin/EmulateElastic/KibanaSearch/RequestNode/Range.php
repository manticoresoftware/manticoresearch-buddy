<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode;

/**
 *  Range node of Kibana search request
 */
final class Range extends BaseRange {

	const EXPR_FUNC = 'range';

	/** @var array<string> $bucketKeys */
	protected array $bucketKeys = [];

	/**
	 * @param string $key
	 * @param string $argField
	 * @param array<int,array{from:float,to:float}> $ranges
	 */
	public function __construct(protected string $key, protected string $argField, protected array $ranges) {
	}

	/**
	 * @return void
	 */
	public function fillInRequest(): void {
		parent::fillInRequest();
		$this->request->addOrderField($this->groupField, 'asc');
	}

	/**
	 * @return void
	 */
	private function makeBucketKeys(): void {
		foreach ($this->ranges as $range) {
			$keyFrom = $keyTo = false;
			if ($range['from']) {
				$newBucket['from'] = $range['from'];
				$keyFrom = (floor($range['from']) === $range['from'])
					? number_format($newBucket['from'], 1)
					: $range['from'];
			}
			if ($range['to']) {
				$newBucket['to'] = $range['to'];
				$keyTo = (floor($range['to']) === $range['to'])
					? number_format($newBucket['to'], 1)
					: $range['to'];
			}
			$bucketKey = $this->generateBucketKey((string)$keyFrom, (string)$keyTo);
			$this->bucketKeys[] = $bucketKey;
		}
	}

	/**
	 * @param string $keyFrom
	 * @param string $keyTo
	 * @return string
	 */
	private static function generateBucketKey(string $keyFrom, string $keyTo): string {
		return (($keyFrom !== '') ? $keyFrom : '*') . '-' . (($keyTo !== '') ? $keyTo : '*');
	}

	/**
	 * @param array<int,array<string,mixed>> $buckets
	 * @return void
	 */
	private function initResponseBuckets(array &$buckets): void {
		if (!$this->bucketKeys) {
			$this->makeBucketKeys();
		}
		foreach ($this->bucketKeys as $i => $key) {
			if (array_key_exists($key, $buckets)) {
				continue;
			}
			$buckets[$key] = [
				'doc_count' => 0,
			];
			$buckets[$key]['from'] = $this->ranges[$i]['from'] ?: 0;
			if (!$this->ranges[$i]['to']) {
				return;
			}
			$buckets[$key]['to'] = $this->ranges[$i]['to'];
		}
	}

	/**
	 * @param array<string,mixed> $responseNode
	 * @param array<string,mixed> $responseRow
	 * @param string $nextNodeKey
	 * @return array<string|int>|false
	 */
	public function fillInResponse(array &$responseNode, array $responseRow, string $nextNodeKey): array|false {
		if (!$this->aliasedFieldExpr) {
			$this->aliasedFieldExpr = $this->fieldAlias ?: $this->fieldExpr;
		}
		$this->makeResponseBucketsIfNotExist($responseNode);
		$this->initResponseBuckets($responseNode[$this->key]['buckets']);
		$buckets = &$responseNode[$this->key]['buckets'];
		// We don't include empty ranges got from Manticore to the response for Kibana
		if (!array_key_exists($this->aliasedFieldExpr, $responseRow)
			|| !is_numeric($responseRow[$this->aliasedFieldExpr])) {
			return [];
		}
		$rangeInd = (int)$responseRow[$this->aliasedFieldExpr];
		// If the current range does not exist in the current node sub-tree,
		// we return false to stop processing this sub-tree
		if (!array_key_exists($rangeInd, $this->bucketKeys)) {
			return false;
		}
		$bucketKey = $this->bucketKeys[$rangeInd];
		if (!array_key_exists($nextNodeKey, $buckets[$bucketKey])) {
			$buckets[$bucketKey][$nextNodeKey] = [];
		}
		$buckets[$bucketKey]['doc_count'] += $responseRow[$this->countField];

		return [$this->key, 'buckets', $bucketKey];
	}
}

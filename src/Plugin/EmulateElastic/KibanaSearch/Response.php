<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\AggNode;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\GroupFilter;

/**
 *  Builds search response to Kibana
 */
final class Response {

	const DEFAULT_RESPONSE = [
		'_shards' => [
			'failed' => 0,
			'skipped' => 0,
			'successful' => 1,
			'total' => 1,
		],
		'aggregations' => [],
		'hits' => [
			'hits' => [],
			'max_score' => null,
			'total' => 0,
		],
		'timed_out' => false,
		'took' => 0,
	];

	/** @var array<mixed> $response */
	private array $response = [];
	/** @var array<mixed> $curNode */
	private array $curNode = [];
	/** @var array<int> $nonTraversableNodeInds */
	private array $nonTraversableNodeInds = [];
	/** @var array<int> $concurrentNodeInds */
	private array $concurrentNodeInds = [];
	/** @var array<string> $nextNodeKeys */
	private array $nextNodeKeys = [];
	/** @var int $nodeCount */
	private int $nodeCount = 0;

	/**
	 * @param array<AggNode> $aggNodes
	 * @param array<GroupFilter> $filterNodes
	 * @param string $countField
	 */
	public function __construct(private array $aggNodes, private array $filterNodes, private string $countField) {
	}

	/**
	 * @return array<mixed>
	 */
	public function get(): array {
		return $this->response ?: static::DEFAULT_RESPONSE;
	}

	/**
	 * @param array<int,array<string,mixed>> $responseData
	 * @return self
	 */
	public function build(array $responseData): self {
		$rootNode = [];
		$total = 0;
		if (!$responseData) {
			$responseData = [
				0 => [],
			];
		}
		$this->nodeCount = sizeof($this->aggNodes);
		$this->findConcurrentNodeInds();
		$this->findNextNodeKeys();
		foreach ($responseData as $dataRow) {
			if ($dataRow) {
				$total += $dataRow[$this->countField];
			}
			$this->curNode = &$rootNode;
			$this->addDataRow($dataRow);
		}

		$this->response = ['aggregations' => $rootNode] + static::DEFAULT_RESPONSE;
		$this->response['hits']['total'] = $total;

		return $this;
	}

	/**
	 * @param array<string,mixed> $dataRow
	 * @return void
	 */
	protected function addDataRow(array $dataRow): void {
		$fillKeys = [];
		foreach ($this->aggNodes as $i => $reqNode) {
			$isNodeTraversable = ($i < $this->nodeCount - 1) && !in_array($i, $this->nonTraversableNodeInds);
			$nextFillKeys = $reqNode->fillInResponse($this->curNode, $dataRow, $this->nextNodeKeys[$i]);
			if ($nextFillKeys === false) {
				break;
			}
			$isNodeConcurrent = in_array($i, $this->concurrentNodeInds);
			if ($nextFillKeys || !$isNodeConcurrent) {
				$fillKeys = $nextFillKeys;
			}
			if (!$isNodeTraversable) {
				continue;
			}
			foreach ($fillKeys as $k) {
				/** @var array<mixed> $curNode */
				$curNode = &$this->curNode[$k];
				$this->curNode = &$curNode;
			}
		}
	}

	/**
	 * @return void
	 */
	protected function findConcurrentNodeInds(): void {
		$lastConcurrentNodeInds = [];
		$curInd = -1;
		foreach ($this->filterNodes as $i => $node) {
			$isConcurrentNode = $node->isConcurrent();
			$isIndSetOver = ($curInd !== -1) && (!$isConcurrentNode || ($i !== $curInd + 1));
			if ($isIndSetOver) {
				$lastConcurrentNodeInds[] = $curInd;
				$curInd = -1;
			}
			if (!$isConcurrentNode) {
				continue;
			}
			$this->concurrentNodeInds[] = $curInd = $i;
		}
		if ($curInd === -1) {
			return;
		}
		$lastConcurrentNodeInds[] = $curInd;
		$this->nonTraversableNodeInds = array_diff($this->concurrentNodeInds, $lastConcurrentNodeInds);
	}

	/**
	 * @return void
	 */
	protected function findNextNodeKeys(): void {
		$curInds = [];
		$i = 0;
		$nextKey = '';
		while ($i < $this->nodeCount - 1) {
			$curInds = [$i];
			$curKey = $this->aggNodes[$i]->getKey();
			$j = $i + 1;
			while ($j < $this->nodeCount && $curKey === ($nextKey = $this->aggNodes[$j]->getKey())) {
				$curInds[] = $j;
				$j++;
			}
			$i = $j;
			if (!$nextKey) {
				continue;
			}
			foreach ($curInds as $ind) {
				$this->nextNodeKeys[$ind] = $nextKey;
			}
		}
		$this->nextNodeKeys[] = '';
	}
}

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
 *  Histogram node of Kibana search request
 */
final class Histogram extends GroupExprNode {

	/**
	 * @param string $key
	 * @param string $argField
	 * @param int $interval
	 * @param bool $isExtendable
	 * @param int|false $intervalFrom
	 * @param int|false $intervalTo
	 */
	public function __construct(
		protected string $key,
		protected string $argField,
		private int $interval,
		private bool $isExtendable,
		private int|false $intervalFrom,
		private int|false $intervalTo
	) {
	}

	/**
	 * @return void
	 */
	public function fillInRequest(): void {
		parent::fillInRequest();
		$this->request->addOrderField($this->groupField, 'asc');
	}

	/**
	 * @param array<string,mixed> $responseNode
	 * @param array<string, mixed> $dataRow
	 * @param string $nextNodeKey
	 * @return array<string|int>|false
	 */
	public function fillInResponse(array &$responseNode, array $dataRow, string $nextNodeKey): array|false {
		$this->makeResponseBucketsIfNotExist($responseNode);
		if (!array_key_exists($this->groupField, $dataRow)) {
			return [];
		}
		$dataFieldVal = $dataRow[$this->groupField];
		$buckets = &$responseNode[$this->key]['buckets'];
		$docCount = $dataRow[$this->countField];
		$bucketKey = $this->findBucket($buckets, 'key', $dataFieldVal);
		if ($bucketKey === -1) {
			// Creating a new histogram bucket
			$bucketKey = sizeof($buckets);
			$buckets[$bucketKey] = [
				'key' => $dataFieldVal,
				'doc_count' => $docCount,
			];
			if ($nextNodeKey) {
				$buckets[$bucketKey][$nextNodeKey] = [];
			}
		} else {
			$buckets[$bucketKey]['doc_count'] += $docCount;
		}

		return [$this->key, 'buckets', $bucketKey];
	}

	/**
	 * @return bool
	 */
	public function isExtendable(): bool {
		return $this->isExtendable;
	}

	/**
	 * @return array{0:int|false,1:int|false}
	 */
	public function getLimits(): array {
		return [$this->intervalFrom, $this->intervalTo];
	}

	/**
	 * @return int
	 */
	public function getInterval(): int {
		return $this->interval;
	}

	/**
	 * @return void
	 */
	protected function makeFieldExpr(): void {
		$this->fieldExpr = "HISTOGRAM({$this->argField}, {hist_interval={$this->interval}})";
	}
}

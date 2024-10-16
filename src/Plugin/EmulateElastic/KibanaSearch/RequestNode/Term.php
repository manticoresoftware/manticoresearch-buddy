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
 *  Term node of Kibana search request
 */
final class Term extends AggNode {

	const RESPONSE_EXTRA_DATA = [
		'doc_count_error_upper_bound' => 0,
		'sum_other_doc_count' => 0,
	];

	/** @var string $groupField */
	private string $groupField = '';
	/** @var string $orderField */
	private string $orderField = '';
	/** @var bool $hasDefaultOrder */
	private bool $hasDefaultOrder = false;

	public function __construct(
		protected string $key,
		private string $orderType,
		private string $orderVal,
		protected string $field,
		private int $size
	) {
	}

	/**
	 * @return int
	 */
	public function getSize(): int {
		return $this->size;
	}

	/**
	 * @return void
	 */
	public function fillInRequest(): void {
		$this->request->addField($this->field);
		$this->setGroupField();
		$this->request->addGroupField($this->groupField);
		if ($this->hasDefaultOrder) {
			$this->request->addOrderField($this->field, 'asc');
		} else {
			if (!$this->orderField) {
				$this->orderField = $this->getOrderField();
			}
			$this->request->addOrderField($this->orderField, $this->orderVal);
		}
	}

	/**
	 * @param array<string,mixed> $responseNode
	 * @param array<string, mixed> $dataRow
	 * @param string $nextNodeKey
	 * @return array<string|int>|false
	 */
	public function fillInResponse(array &$responseNode, array $dataRow, string $nextNodeKey): array|false {
		$this->makeResponseBucketsIfNotExist($responseNode, self::RESPONSE_EXTRA_DATA);
		if (!array_key_exists($this->field, $dataRow)) {
			return [];
		}
		$dataFieldVal = $dataRow[$this->field];
		$buckets = &$responseNode[$this->key]['buckets'];
		$docCount = $dataRow[$this->request->getCountField()];
		$bucketKey = $this->findBucket($buckets, 'key', $dataFieldVal);
		if ($bucketKey === -1) {
			// Creatign a new term bucket
			$bucketKey = sizeof($buckets);
			$buckets[$bucketKey] = [
				'key' => $dataFieldVal,
				'doc_count' => $docCount,
			];
			if ($nextNodeKey && !array_key_exists($nextNodeKey, $buckets[$bucketKey])) {
				$buckets[$bucketKey][$nextNodeKey] = [];
			}
		} else {
			$buckets[$bucketKey]['doc_count'] += $docCount;
		}

		return [$this->key, 'buckets', $bucketKey];
	}

	/**
	 * @param bool $isDefaultOrder
	 * @return void
	 */
	public function setDefaultOrder(bool $isDefaultOrder): void {
		$this->hasDefaultOrder = $isDefaultOrder;
	}

	/**
	 * @return bool
	 */
	public function hasDefaultOrder(): bool {
		return $this->hasDefaultOrder;
	}

	/**
	 * @return string
	 */
	public function getOrderField(): string {
		if ($this->orderField) {
			return $this->orderField;
		}
		return match ($this->orderType) {
			'_count' => $this->countField,
			'_key' => $this->field,
			default => $this->orderType,
		};
	}

	/**
	 * @return string
	 */
	public function getOrder(): string {
		return $this->orderVal;
	}

	/**
	 * @param string $orderField
	 * @return void
	 */
	public function setOrderField(string $orderField): void {
		$this->orderField = $orderField;
	}

	/**
	 * @return void
	 */
	public function setGroupField(): void {
		$this->groupField = $this->field;
	}

	/**
	 * @return string
	 */
	public function getGroupField(): string {
		return $this->groupField;
	}

	/**
	 * @return bool
	 */
	public function hasGroupField(): bool {
		return true;
	}
}

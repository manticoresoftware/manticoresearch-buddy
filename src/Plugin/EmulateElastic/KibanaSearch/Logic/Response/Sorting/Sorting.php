<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Sorting;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\BaseLogic;
// @phpcs:ignore
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Sorting\Metric\CalculatorFactory
	as MetricCalculatorFactory;
// @phpcs:ignore
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Sorting\Metric\MetricCalculatorInterface;
// @phpcs:ignore
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Sorting\Metric\MetricUpdaterInterface;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Metric;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Term;

/**
 *  Processes complex sorting and limiting of Manticore's response data by its single ordered fields
 */
class Sorting extends BaseLogic {

	/** @var array<string,MetricCalculatorInterface> $metricCalculators */
	protected array $metricCalculators = [];
	/** @var array<string,MetricUpdaterInterface> $metricUpdaters */
	protected array $metricUpdaters = [];
	/** @var array<SortField> $sortFields */
	protected array $sortFields = [];
	/** @var int $lastSortFieldInd */
	protected int $lastSortFieldInd = -1;
	/** @var bool $isAvailable */
	protected bool $isAvailable = true;
	/** @var array<int,array<string,mixed>> $responseRows */
	protected array $responseRows = [];
	/** @var bool $areFieldsPresorted */
	protected bool $areFieldsPresorted;

	/**
	 * @param array<string> $groupFieldNames
	 * @param array<Term> $orderedNodes
	 * @param array<Metric> $metricNodes
	 * @param MetricCalculatorFactory $metricCalculatorFactory
	 */
	public function __construct(
		protected array $groupFieldNames,
		protected array $orderedNodes,
		protected array $metricNodes,
		protected MetricCalculatorFactory $metricCalculatorFactory
	) {
	}

	/**
	 * @return bool
	 */
	public function isAvailable(): bool {
		return !!sizeof($this->orderedNodes);
	}

	/**
	 * @return bool
	 */
	public function areFieldsPresorted(): bool {
		return $this->areFieldsPresorted;
	}

	/**
	 * @return static
	 */
	public function apply(): static {
		if (!$this->responseRows) {
			return $this;
		}
		$this->areFieldsPresorted = !$this->orderedNodes[array_key_first($this->orderedNodes)]->hasDefaultOrder();
		$this->createSortFields();
		$sortedRowInds = array_flip(
			$this->sortFields[0]->process(0, sizeof($this->responseRows) - 1)
		);
		// Removing rows that exceed the limits after sorting
		$rowKeys = array_keys($this->responseRows);
		for ($rowCount = sizeof($this->responseRows), $i = 0; $i < $rowCount; $i++) {
			if (isset($sortedRowInds[$rowKeys[$i]])) {
				continue;
			}
			unset($this->responseRows[$rowKeys[$i]]);
		}
		uksort(
			$this->responseRows,
			fn($a, $b) => $sortedRowInds[$a] <=> $sortedRowInds[$b]
		);
		// Adding aggregate metric values calculated for the respective values of group fields
		$this->metricUpdaters = array_filter(
			$this->metricCalculators,
			fn ($obj) => $obj instanceof MetricUpdaterInterface
		);
		if (!$this->metricUpdaters) {
			return $this;
		}
		$this->addAggregateMetricValues();

		return $this;
	}

	/**
	 * @return void
	 */
	protected function addAggregateMetricValues() {
		$metricInfo = [];
		foreach ($this->metricNodes as $node) {
			$metricInfo[$node->getKey()] = $node->getFieldAlias();
		}
		foreach ($this->metricUpdaters as $metricUpdater) {
			foreach ($metricUpdater->getUpdates() as $rowInd => $rowUpdates) {
				// Some rows with calculated metric values can be cut off at this point
				if (!array_key_exists($rowInd, $this->responseRows)) {
					break;
				}
				foreach ($rowUpdates as $metricKey => $updValue) {
					if (array_key_exists($metricInfo[$metricKey], $this->responseRows[$rowInd])) {
						return;
					}
					$this->responseRows[$rowInd][$metricKey] = $updValue;
				}
			}
		}
	}

	/**
	 * Getting helpers to calculate aggregate values for metric fields
	 *
	 * @param string $fieldName
	 * @return MetricCalculatorInterface
	 */
	public function getMetricCalculator(string $fieldName): MetricCalculatorInterface {
		if (!array_key_exists($fieldName, $this->metricCalculators)) {
			$this->metricCalculators[$fieldName] = (array_key_exists($fieldName, $this->metricNodes))
				? $this->metricCalculatorFactory->createFromNode($this->metricNodes[$fieldName])
				: $this->metricCalculatorFactory->create();
		}

		return $this->metricCalculators[$fieldName];
	}

	/**
	 * @param int $fieldInd
	 * @return bool
	 */
	public function isLastSortField(int $fieldInd): bool {
		return $fieldInd === $this->lastSortFieldInd;
	}

	/**
	 * @param int $fieldInd
	 * @return SortField|false
	 */
	public function getSortFieldByInd(int $fieldInd): SortField|false {
		return array_key_exists($fieldInd, $this->sortFields) ? $this->sortFields[$fieldInd] : false;
	}

	/**
	 * Creating sortField objects based on data from the fields that need to be sorted
	 *
	 * @return void
	 */
	protected function createSortFields(): void {
		$j = 0;
		foreach ($this->groupFieldNames as $i => $field) {
			$orderedNode = array_key_exists($i, $this->orderedNodes) ? $this->orderedNodes[$i] : false;
			$fieldProps = $orderedNode
				? [$orderedNode->getSize(), $orderedNode->getOrder(), $orderedNode->getOrderField()]
				: [0, 'asc', $field];
			$this->sortFields[] = new SortField($this, $j, $field, ...$fieldProps);
			$j++;
		}
		$this->lastSortFieldInd = $j - 1;
	}
}

<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Sorting;

// @phpcs:ignore
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Sorting\Metric\MetricCalculatorInterface;

/**
 *  Represents a data field to be sorted and cut off
 */
final class SortField {

	/** @var array<array{0:int,1:int}> $subsets */
	private array $subsets = [];
	/** @var array<int,array<string,mixed>> $dataRows */
	private array $dataRows = [];
	/** @var MetricCalculatorInterface|false $metricCalculator */
	private MetricCalculatorInterface|false $metricCalculator = false;

	public function __construct(
		private Sorting $sorting,
		private int $fieldInd,
		private string $name,
		private int $limit,
		private string $order,
		private string $orderField
	) {
		$this->dataRows = $this->sorting->getResponseRows();
		if ($this->name === $this->orderField) {
			return;
		}
		$this->metricCalculator = $this->sorting->getMetricCalculator($this->orderField);
	}

	/**
	 * Executing the merge sort algorithm recursively for all sort fields
	 *
	 * @return array<int>
	 */
	public function process(int $rowIndFrom, int $rowIndTo): array {
		$this->split($rowIndFrom, $rowIndTo);

		return $this->merge(
			$this->sortAllSubsets()
		);
	}

	/**
	 * @param int $rowIndFrom
	 * @param int $rowIndTo
	 * @return void
	 */
	private function split(int $rowIndFrom, int $rowIndTo): void {
		$this->subsets = [];
		$prevRow = false;
		$prevChangeInd = $rowIndFrom - 1;
		$rowKeys = array_keys($this->dataRows);
		for ($i = $rowIndFrom; $i <= $rowIndTo; $i++) {
			$rowKey = $rowKeys[$i];
			if ($prevRow && $prevRow[$this->name] !== $this->dataRows[$rowKey][$this->name]) {
				$this->subsets[] = [$prevChangeInd + 1, $i - 1];
				$prevChangeInd = $i - 1;
			}
			$prevRow = $this->dataRows[$rowKey];
		}
		$this->subsets[] = [$prevChangeInd + 1, $rowIndTo];
	}

	/**
	 * @param array{0:int,1:int} $subset
	 * @return array<int>
	 */
	private function sortSubset(array $subset): array {
		[$subsetFrom, $subsetTo] = $subset;
		/** @var array<int,array<string,mixed>> $subsetSortRows */
		$subsetSortRows = array_slice($this->dataRows, $subsetFrom, $subsetTo - $subsetFrom + 1, true);
		if ($this->metricCalculator) {
			$this->metricCalculator->calc($subsetSortRows, $this->orderField);
			$sortField = $this->orderField;
		} else {
			$sortField = $this->name;
		}
		// Keeping row keys for the following sorting here
		$subsetSortVals = array_map(
			fn ($row) => $row[$sortField],
			$subsetSortRows
		);
		$this->sort($subsetSortVals);

		/** @var array<int> $sortKeys */
		$sortKeys = array_keys($subsetSortVals);
		return $sortKeys;
	}

	/**
	 * @return array<int,array<int>>
	 */
	private function sortAllSubsets(): array {
		$sortedSubsets = [];
		$isLastField = $this->sorting->isLastSortField($this->fieldInd);

		foreach ($this->subsets as $subset) {
			[$subsetRowFrom, $subsetRowTo] = $subset;
			if ($isLastField) {
				$sortedSubsets[] = $this->sorting->areFieldsPresorted()
					? range($subsetRowFrom, $subsetRowTo)
					: $this->sortSubset($subset);
			} else {
				/** @var self $nextSortField */
				$nextSortField = $this->sorting->getSortFieldByInd($this->fieldInd + 1);
				$sortedSubsets[] = $nextSortField->process($subsetRowFrom, $subsetRowTo);
			}
		}

		return $sortedSubsets;
	}

	/**
	 * @param array<int> $subsetKeys
	 */
	private function orderSubsetsByMetric(array &$subsetKeys): void {
		if (!$this->metricCalculator) {
			return;
		}
		$mergeOrderVals = [];
		foreach ($this->subsets as [$subsetFrom, $subsetTo]) {
			$subsetSortRows = array_slice($this->dataRows, $subsetFrom, $subsetTo - $subsetFrom + 1, true);
			$mergeOrderVals[] = $this->metricCalculator->calc($subsetSortRows, $this->orderField);
		}
		if ($this->sorting->areFieldsPresorted() || sizeof($mergeOrderVals) < 2) {
			return;
		}
		$this->sort($mergeOrderVals);
		usort(
			$subsetKeys,
			fn($a, $b) => ($this->order === 'asc')
				? $mergeOrderVals[$a] <=> $mergeOrderVals[$b]
				: $mergeOrderVals[$b] <=> $mergeOrderVals[$a]
		);
	}

	/**
	 * @param array<int,array<int>> $sortedSubsets
	 * @return array<int>
	 */
	private function merge(array $sortedSubsets): array {
		$keys = array_keys($sortedSubsets);
		$this->orderSubsetsByMetric($keys);

		// Limiting the results if needed
		if ($this->limit) {
			array_splice($keys, $this->limit);
		}

		$sortedRowInds = [];
		foreach ($keys as $key) {
			$sortedRowInds = [...$sortedRowInds, ...$sortedSubsets[$key]];
		}

		return $sortedRowInds;
	}

	/**
	 * @param array<mixed> $vals
	 * @return void
	 */
	private function sort(array &$vals): void {
		if ($this->order === 'asc') {
			asort($vals);
		} else {
			arsort($vals);
		}
	}
}

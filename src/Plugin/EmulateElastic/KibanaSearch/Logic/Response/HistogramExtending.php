<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Histogram;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Term;

/**
 *  Adds rows corresponding to missing histogram intervals to the response,
 *  thus imitating Elastic's behaviour
 */
class HistogramExtending extends BaseLogic {

	/** @var array<int,Histogram> $histograms */
	protected array $histograms = [];
	/** @var array<string,int> $histIntervals */
	protected array $histIntervals = [];
	/** @var array{min:array<int>,max:array<int>} $histLimits */
	protected array $histLimits;
	/** @var array<string> $histFields */
	protected array $histFields = [];
	/** @var int $histCount */
	protected int $histCount = 0;
	/** @var array<int,string> $termFields */
	protected array $termFields = [];
	/** @var array<mixed> $termFieldVals */
	protected array $termFieldVals = [];
	/** @var bool $isAvailable */
	protected bool $isAvailable;

	/**
	 * @param array<int,Histogram> $histogramNodes
	 * @param array<int,string> $metricFields
	 * @param array<int,Term> $termNodes
	 * @param string $countField
	 */
	public function __construct(
		protected array $histogramNodes,
		protected array $metricFields,
		protected array $termNodes,
		protected string $countField
	) {
	}

	/**
	 * @return bool
	 */
	public function isAvailable(): bool {
		$this->histograms = array_filter(
			$this->histogramNodes,
			fn ($node) => $node->isExtendable()
		);
		if (!$this->histograms) {
			return false;
		}

		$maxHistNodeInd = array_key_last($this->histograms);
		$this->termFields = array_map(
			fn ($termNode) => $termNode->getField(),
			$this->termNodes
		);
		$maxGroupNodeInd = array_key_last($this->termFields);

		return $maxHistNodeInd > $maxGroupNodeInd;
	}

	/**
	 * @return static
	 */
	public function apply(): static {
		if (!$this->responseRows) {
			return $this;
		}
		$this->histFields = array_values(
			array_map(fn ($node) => $node->getFieldAlias(), $this->histograms)
		);
		$this->histCount = sizeof($this->histFields);
		$this->findHistParams();
		$curValSet = $curRow = $prevRow = [];
		$rowInd = 0;
		foreach ($this->responseRows as $curRow) {
			$rowValSet = $this->getHistValSet($curRow);
			if (!$this->isGroupFieldSetChanged($curRow)) {
				$rowInd += $this->updateRows($rowInd, $curRow, $curValSet, $rowValSet);
			} elseif (isset($this->histLimits)) {
				$maxValSet = $this->calcLimitSet('max', $curValSet);
				$rowInd += $this->updateRows($rowInd, $prevRow, $curValSet, $maxValSet, true);
				$minValSet = $this->calcLimitSet('min', $rowValSet);
				$rowInd += $this->updateRows($rowInd, $curRow, $minValSet, $rowValSet);
			}
			$curValSet = $rowValSet;
			$prevRow = $curRow;
			$rowInd++;
		}

		if (!isset($this->histLimits) || !$curRow) {
			return $this;
		}
		$maxValSet = $this->calcLimitSet('max', $curValSet);
		$this->updateRows($rowInd, $curRow, $curValSet, $maxValSet, true);

		return $this;
	}

	/**
	 * @return void
	 */
	protected function findHistParams(): void {
		$minHistLimits = $maxHistLimits = [];
		foreach ($this->histograms as $node) {
			$field = $node->getFieldAlias();
			$this->histIntervals[$field] = $node->getInterval();
			[$minLimit, $maxLimit] = $node->getLimits();
			if ($minLimit === false || $maxLimit === false) {
				continue;
			}
			$minHistLimits[$field] = $minLimit;
			$maxHistLimits[$field] = $maxLimit;
		}
		if (!$minHistLimits) {
			return;
		}
		$this->histLimits = [
			'min' => $minHistLimits,
			'max' => $maxHistLimits,
		];
	}

	/**
	 * @param array<string,mixed> $row
	 * @return bool
	 */
	protected function isGroupFieldSetChanged(array $row): bool {
		$rowTermFieldVals = array_map(fn ($field) => $row[$field], $this->termFields);
		if (!$this->termFieldVals) {
			$this->termFieldVals = $rowTermFieldVals;
			return true;
		}

		foreach ($this->termFieldVals as $i => $val) {
			if ($val !== $rowTermFieldVals[$i]) {
				$this->termFieldVals = $rowTermFieldVals;
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,int>
	 */
	protected function getHistValSet(array $row): array {
		/** @var array<string,int> $histValSet */
		$histValSet = array_filter(
			$row,
			fn ($field) => in_array($field, $this->histFields),
			ARRAY_FILTER_USE_KEY
		);
		return $histValSet;
	}

	/**
	 * @param int $rowInd
	 * @param array<string,mixed> $curRow
	 * @param array<int> $valSetFrom
	 * @param array<int> $valSetTo
	 * @param bool $isLastRowFill
	 * @return int
	 */
	protected function updateRows(
		int $rowInd,
		array $curRow,
		array $valSetFrom,
		array $valSetTo,
		bool $isLastRowFill = false
	): int {
		if (!$valSetFrom || !$valSetTo || $valSetFrom === $valSetTo) {
			return 0;
		}
		// Setting empty values for all metric fields
		foreach (array_keys($curRow) as $field) {
			if ($field === $this->countField) {
				$curRow[$field] = 0;
			} elseif (in_array($field, $this->metricFields)) {
				$curRow[$field] = '';
			}
		}
		$extraRows = $this->makeExtraRows($curRow, $valSetFrom, $valSetTo, $isLastRowFill);
		if ($extraRows) {
			array_splice($this->responseRows, $rowInd, 0, $extraRows);
		}

		return sizeof($extraRows);
	}

	/**
	 * @param array<string,mixed> $curRow
	 * @param array<int> $valSetFrom
	 * @param array<int> $valSetTo
	 * @param bool $isLastRowFill
	 * @return array<array<string,mixed>>
	 */
	protected function makeExtraRows(array $curRow, array $valSetFrom, array $valSetTo, bool $isLastRowFill): array {
		$curValSet = $valSetFrom;
		$extraRows = [];
		while (true) {
			$j = $this->histCount;
			do {
				$j--;
				$field = $this->histFields[$j];
				$curValSet[$field] += $this->histIntervals[$field];
			} while ($j > 0 && $curValSet[$field] > $valSetTo[$field]);
			for ($k = $j + 1; $k < $this->histCount; $k++) {
				$nextField = $this->histFields[$k];
				$curValSet[$nextField] = $valSetFrom[$nextField];
			}
			if ($curValSet === $valSetTo) {
				break;
			}
			$extraRows[] = $curValSet + $curRow;
		}
		if ($isLastRowFill) {
			$extraRows[] = $curValSet + $curRow;
		}

		return $extraRows;
	}

	/**
	 * @param string $setType
	 * @param array<string,int> $rowValSet
	 * @return array<int>
	 */
	protected function calcLimitSet(string $setType, array $rowValSet): array {
		$limitSet = [];
		foreach (array_keys($rowValSet) as $field) {
			$limitSet[$field] = array_key_exists($field, $this->histLimits[$setType])
				? $this->histLimits[$setType][$field]
				: $rowValSet[$field];
		}
		return $limitSet;
	}
}

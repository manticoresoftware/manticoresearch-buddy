<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\BaseLogic;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\GroupFilter;

/**
 *  Processes response data rows that must be filtered out by Kibana's filtering rules
 *  removing them or setting empty the values of their aggregation fields
 */
class UnmatchedFilterProcessing extends BaseLogic {

	/** @var array<string> $filterFields */
	protected array $filterFields = [];
	/** @var int $delCandidateRowInd */
	protected int $delCandidateRowInd = -1;

	/**
	 * @param array<GroupFilter> $filterNodes
	 * @param array<string> $groupFields
	 * @param array<string> $metricFields
	 * @param string $countField
	 */
	public function __construct(
		protected array $filterNodes,
		protected array $groupFields,
		protected array $metricFields,
		protected string $countField,
	) {
		$this->filterFields = array_map(
			fn ($node) => $node->getFieldAlias() ?: $node->getField(),
			$this->filterNodes
		);
	}

	/**
	 * Checks if there're any not direct filters in the request.
	 * Otherwise, this processing is not needed
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		foreach ($this->filterNodes as $node) {
			if (!$node->isDirect()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return static
	 */
	public function apply(): static {
		if (!$this->responseRows) {
			return $this;
		}
		$checkDataRow = $this->responseRows[array_key_first($this->responseRows)];
		$activeFilterFields = array_filter(
			$this->filterFields,
			fn ($field) => array_key_exists($field, $checkDataRow)
		);
		$otherGroupFields = array_filter(
			$this->groupFields,
			fn ($field) => !in_array($field, $activeFilterFields)
		);
		$prevGroupFieldVals = $curGroupFieldVals = [];
		foreach ($this->responseRows as $i => $row) {
			$curGroupFieldVals = array_map(fn ($f) => $row[$f], $otherGroupFields);
			$hasSameGroupData = ($curGroupFieldVals === $prevGroupFieldVals);
			$hasNoMatchedFilters = array_reduce(
				$activeFilterFields,
				fn ($res, $f) => $res && !$row[$f],
				true
			);
			$this->emptyMetricValuesForUnmatchedFilters($i, $hasNoMatchedFilters, $hasSameGroupData);
			$prevGroupFieldVals = $curGroupFieldVals;
		}
		if ($this->delCandidateRowInd === -1) {
			return $this;
		}
		// Removing the last detected row if such exists
		unset($this->responseRows[$this->delCandidateRowInd]);

		return $this;
	}

	/**
	 * If a row doesn't match any of filters its metric fields must be set as empty;
	 * if such row is the only one in a group fieldset it must be removed
	 *
	 * @param int $rowInd
	 * @param bool $hasNoMatchedFilters
	 * @param bool $hasSameGroupData
	 * @return void
	 */
	protected function emptyMetricValuesForUnmatchedFilters(
		int $rowInd,
		bool $hasNoMatchedFilters,
		bool $hasSameGroupData
	): void {
		if (!$hasSameGroupData) {
			if ($hasNoMatchedFilters) {
				$this->responseRows[$rowInd][$this->countField] = 0;
				foreach ($this->metricFields as $field) {
					$this->responseRows[$rowInd][$field] = '';
				}
				$this->delCandidateRowInd = $rowInd;
			} elseif ($this->delCandidateRowInd !== -1) {
				$this->delCandidateRowInd = -1;
			}
			return;
		}
		if ($hasNoMatchedFilters) {
			unset($this->responseRows[$rowInd]);
			return;
		}
		if ($this->delCandidateRowInd === -1) {
			return;
		}
		unset($this->responseRows[$this->delCandidateRowInd]);
		$this->delCandidateRowInd = -1;
	}
}

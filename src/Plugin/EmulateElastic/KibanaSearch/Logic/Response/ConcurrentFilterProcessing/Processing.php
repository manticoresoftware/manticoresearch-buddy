<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\ConcurrentFilterProcessing;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\BaseLogic;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\GroupFilter;

/**
 *  Processes 'concurrent' filters from the Kibana request,
 *  i.e. the ones, that must be treated as if the parts of the MySQL UNION query
 */
class Processing extends BaseLogic {

	/** @var array<string> $filterFields */
	protected array $filterFields = [];
	/** @var array<FilterSet> $concurrentFilterSets */
	protected array $concurrentFilterSets = [];

	/**
	 * @param array<GroupFilter> $filterNodes
	 */
	public function __construct(protected array $filterNodes) {
		$this->filterFields = array_map(
			fn ($node) => $node->getFieldAlias() ?: $node->getField(),
			$this->filterNodes
		);
	}

	/**
	 * @return bool
	 */
	public function isAvailable(): bool {
		foreach ($this->filterNodes as $node) {
			if ($node->isConcurrent()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * If a data row matches multiple Kibana's concurrent filters,
	 * we need to convert it to multiple rows matching a single filter each,
	 * thus providing a following correct build of Kibana response
	 *
	 * @return static
	 */
	public function apply(): static {
		if (!$this->responseRows) {
			return $this;
		}
		$this->createConcurrentFilterSets();
		$rowInd = 0;
		foreach ($this->responseRows as $row) {
			$rowShift = 1;
			foreach ($this->concurrentFilterSets as $filterSet) {
				$filterSetRows = $filterSet->check($row);
				if (!$filterSetRows) {
					continue;
				}
				array_splice($this->responseRows, $rowInd, 1, $filterSetRows);
				$rowShift += sizeof($filterSetRows) - 1;
			}
			$rowInd += $rowShift;
		}

		return $this;
	}

	/**
	 * @return void
	 */
	protected function createConcurrentFilterSets(): void {
		$concurrentFilterInds = array_filter(
			array_keys($this->filterNodes),
			fn ($key) => $this->filterNodes[$key]->isConcurrent()
		);
		$i0 = -1;
		$filterSet = new FilterSet();
		foreach ($concurrentFilterInds as $i) {
			if ($i0 !== -1 && $i > $i0 + 1) {
				$this->concurrentFilterSets[] = $filterSet;
				$filterSet = new FilterSet();
			}
			$filterField = $this->filterNodes[$i]->getFieldAlias() ?: $this->filterNodes[$i]->getField();
			$filterSet->addField($filterField);
			$i0 = $i;
		}
		if (!$filterSet->getFields()) {
			return;
		}
		$this->concurrentFilterSets[] = $filterSet;
	}
}

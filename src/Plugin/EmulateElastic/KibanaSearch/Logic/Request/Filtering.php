<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Interfaces\RequestLogicInterface;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\GroupFilter;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\QueryFilter;

/**
 *  Checks filter objects from the Kibana request to determine their type
 *  and if they can be applied directly in Manticore request
 *  or need to be used later, in post-processing Manticore response
 */
class Filtering implements RequestLogicInterface {

	/** @var bool $hasQueryFilter */
	protected bool $hasQueryFilter = true;
	/** @var array<int> $concurrentFilterInds */
	protected array $concurrentFilterInds = [];

	/**
	 * @param array<GroupFilter> $filterNodes
	 * @param QueryFilter $queryFilterNode
	 * @param int $lastGroupNodeInd
	 */
	public function __construct(
		protected array $filterNodes,
		protected QueryFilter $queryFilterNode,
		protected int $lastGroupNodeInd
	) {
	}

	/**
	 * @return array<GroupFilter>
	 */
	public function getFilterNodes(): array {
		return $this->filterNodes;
	}

	/**
	 * @return array<int>
	 */
	public function getConcurrentFilterInds(): array {
		return $this->concurrentFilterInds;
	}

	/**
	 * Determining filters that cannot be directly used in the request
	 * since they aren't intended to actually filter data, but must be used later in postprocessing
	 *
	 * @return static
	 */
	public function apply(): static {
		$this->checkQueryFilterValidity();
		$this->findConcurrentFilterInds();
		foreach ($this->filterNodes as $i => $node) {
			// Unnamed group filters passed together with an active query filter aren't needed for the request
			if ($this->hasQueryFilter && !$node->getName()) {
				$node->disable();
				continue;
			}
			$isConcurrentFilter = in_array($i, $this->concurrentFilterInds);
			if ($isConcurrentFilter) {
				$node->setAsConcurrent();
			}
			if ($isConcurrentFilter || ($this->lastGroupNodeInd !== -1 && $i > $this->lastGroupNodeInd)) {
				$node->setAsIndirect();
			}
			if ($node->buildWhereExpr()) {
				continue;
			}
			$node->disable();
		}

		return $this;
	}

	/**
	 * @return void
	 */
	protected function checkQueryFilterValidity(): void {
		$this->hasQueryFilter = $this->queryFilterNode->hasFilterData();
		if ($this->hasQueryFilter) {
			return;
		}
		$this->queryFilterNode->disable();
	}

	/**
	 * Detecting 'concurrent' filters from the Kibana request,
	 * i.e. the ones than need to be processed as if the parts of the MySQL's UNION query
	 *
	 * @return void
	 */
	protected function findConcurrentFilterInds(): void {
		$concurrentFilterInds = [];
		foreach ($this->filterNodes as $i => $node) {
			$key = $node->getKey();
			if (!array_key_exists($key, $concurrentFilterInds)) {
				$concurrentFilterInds[$key] = [$i];
			} else {
				$concurrentFilterInds[$key][] = $i;
			}
		}

		$this->concurrentFilterInds = array_merge(
			...array_values(
				array_filter(
					$concurrentFilterInds,
					fn ($item) => sizeof($item) > 1
				)
			)
		);
	}
}

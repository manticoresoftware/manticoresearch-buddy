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
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Metric;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Term;

/**
 *  Sets ordering logic for SphinxQL request and, if needed, for SphinxQL response
 */
class Ordering implements RequestLogicInterface {

	/** @var bool $hasMetricOrders */
	protected bool $hasMetricOrders = false;
	/** @var bool $isComplex */
	protected bool $isComplex = false;

	/**
	 * @param array<Term> $orderedNodes
	 * @param array<Metric> $metricNodes
	 * @param array<GroupFilter> $groupFilterNodes
	 * @param int $groupExprNodeCount
	 */
	public function __construct(
		private array $orderedNodes,
		private array $metricNodes,
		private array $groupFilterNodes,
		private int $groupExprNodeCount
	) {
	}

	/**
	 * Checking if the request requires other ordering than by a single metric or by group fields themselves.
	 * If so, ordering cannot be implemented in Manticore and will require postprocessing
	 *
	 * @return static
	 */
	public function apply(): static {
		$orderFields = $this->determineOrderFields();
		$this->determineIfComplex();
		foreach ($this->orderedNodes as $i => $node) {
			// If there's a complex ordering, we just use the default order of the node field
			// and perform actual ordering later, in post-processing
			if ($this->isComplex) {
				$node->setDefaultOrder(true);
			}
			if (!$orderFields[$i]) {
				continue;
			}
			$node->setOrderField($orderFields[$i]);
		}

		return $this;
	}

	/**
	 * @return void
	 */
	protected function determineIfComplex(): void {
		$orderedNodeCount = sizeof($this->orderedNodes);
		$filterNodeCount = sizeof(
			array_filter(
				$this->groupFilterNodes,
				fn ($node) => !($node->isDisabled() || $node->isDirect())
			)
		);
		$groupNodeCount = $orderedNodeCount + $filterNodeCount + $this->groupExprNodeCount;
		// If there're multiple group fields to be ordered or ordering includes metric fields,
		// it can not be executed directly by Manticore
		$this->isComplex = ($groupNodeCount > 1)
			&& ($this->hasMetricOrders || ($groupNodeCount > $orderedNodeCount));
	}

	/**
	 * @return array<Term>
	 */
	public function getOrderedNodes(): array {
		return $this->orderedNodes;
	}

	/**
	 * @return array<Metric>
	 */
	public function getMetricNodes(): array {
		return $this->metricNodes;
	}

	/**
	 * @return bool
	 */
	public function isComplex(): bool {
		return $this->isComplex;
	}

	/**
	 * Determines which request fields need to be ordered
	 *
	 * @return array<int,string>
	 */
	protected function determineOrderFields(): array {
		$orderFields = [];
		$this->hasMetricOrders = false;
		foreach ($this->orderedNodes as $i => $node) {
			$origOrderField = $node->getOrderField();
			$nodeField = $node->getField();
			if ($origOrderField === $nodeField) {
				$orderFields[$i] = '';
				continue;
			}
			if (!$this->hasMetricOrders) {
				$this->hasMetricOrders = true;
			}
			$orderField = $this->determineNodeOrderField($origOrderField);
			$orderFields[$i] = $orderField;
		}

		return $orderFields;
	}

	/**
	 * @param string $origOrderField
	 * @return string
	 */
	protected function determineNodeOrderField(string $origOrderField): string {
		foreach ($this->metricNodes as $node) {
			if ($node->getKey() === $origOrderField || $node->getName() === $origOrderField) {
				return $node->getFieldAlias();
			}
		}
		return $origOrderField;
	}
}

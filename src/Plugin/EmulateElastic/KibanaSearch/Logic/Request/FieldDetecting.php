<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Interfaces\FailableLogicInterface;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\AggNode;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\DateHistogram;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Histogram;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Term;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\SphinxQLRequest;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\TableFieldInfo;

/**
 *  Checks if Kibana's request fields exist in the given table
 *  Used in cases when the Kibana request relates to multiple tables
 */
class FieldDetecting implements FailableLogicInterface {

	/** @var bool $isFailed */
	protected bool $isFailed = false;
	/** @var array<string> $allArgFields */
	protected array $allArgFields = [];

	/**
	 * @param array<AggNode> $aggNodes
	 * @param array<int,array<string>> $argFieldInfo
	 * @param TableFieldInfo $tableFieldInfo
	 * @param SphinxQLRequest $sphinxQLRequest
	 */
	public function __construct(
		protected array $aggNodes,
		protected array $argFieldInfo,
		protected TableFieldInfo $tableFieldInfo,
		protected SphinxQLRequest $sphinxQLRequest
	) {
		foreach ($this->argFieldInfo as $nodeArgFields) {
			$this->allArgFields = [...$this->allArgFields, ...$nodeArgFields];
		}
	}

	/**
	 * @return bool
	 */
	public function isFailed(): bool {
		return $this->isFailed;
	}

	/**
	 * @return static
	 */
	public function apply(): static {
		$this->isFailed = false;
		$tableFields = $this->tableFieldInfo->getFieldNamesByTable(
			$this->sphinxQLRequest->getTable()
		);
		// If a table misses all fields necessary for the given request, skip it
		if ($this->areCrucialFieldsMissing($tableFields)) {
			$this->isFailed = true;
			$this->disableAllNodes();

			return $this;
		}
		// If multiple tables present, we don't peform direct grouping for argument fields in Kibana metric nodes
		// so we disable such nodes
		foreach (array_keys($this->argFieldInfo) as $i) {
			if (!array_diff($this->argFieldInfo[$i], $tableFields)) {
				continue;
			}
			$this->aggNodes[$i]->disable();
		}

		return $this;
	}

	/**
	 * @param array<string> $tableFields
	 * @return bool
	 */
	protected function areCrucialFieldsMissing(array $tableFields): bool {
		$maxIntervalGroupNodeInd = $maxValueGroupNodeInd = -1;

		if (!array_intersect($this->allArgFields, $tableFields)) {
			// If no request fields exist in the table, request to that table won't give any result
			return true;
		}
		foreach ($this->argFieldInfo as $i => $nodeArgFields) {
			$isValueGroupNode = $this->isValueGroupNode($this->aggNodes[$i]);
			if (array_intersect($nodeArgFields, $tableFields)) {
				if ($isValueGroupNode) {
					$maxValueGroupNodeInd = $i;
				}
				continue;
			}
			if ($isValueGroupNode) {
				// If any value group node is missing, request to the table won't give any result
				return true;
			}
			$maxIntervalGroupNodeInd = $i;
		}

		// If a filter/range node preceding any value group node is missing, request to the table won't give any result
		return ($maxIntervalGroupNodeInd !== -1) && ($maxIntervalGroupNodeInd < $maxValueGroupNodeInd);
	}

	/**
	 * @param AggNode $node
	 * @return bool
	 */
	protected static function isValueGroupNode(AggNode $node): bool {
		return is_a($node, Term::class) || is_a($node, Histogram::class) || is_a($node, DateHistogram::class);
	}

	/**
	 * @return void
	 */
	protected function disableAllNodes(): void {
		foreach ($this->aggNodes as $node) {
			$node->disable();
		}
	}
}

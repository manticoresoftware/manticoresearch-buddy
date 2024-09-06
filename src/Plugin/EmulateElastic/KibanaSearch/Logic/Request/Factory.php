<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Aliasing;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\FieldDetecting;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Filtering;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Interfaces\RequestLogicInterface;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Ordering;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\NodeSet;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\AggNode;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\GroupExprNode;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\GroupFilter;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Interfaces\AliasedNodeInterface;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Metric;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\QueryFilter;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Term;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\SphinxQLRequest;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\TableFieldInfo;

/**
 * Creates node objects from Kibana request data
 */
class Factory {

	/**
	 * @param NodeSet $nodeSet
	 * @param SphinxQLRequest $sphinxQLRequest
	 * @param TableFieldInfo $tableFieldInfo
	 */
	public function __construct(
		protected NodeSet $nodeSet,
		protected SphinxQLRequest $sphinxQLRequest,
		protected TableFieldInfo $tableFieldInfo
	) {
	}

	/**
	 * @param string $logicName
	 * @return RequestLogicInterface
	 * @throws \Exception
	 */
	public function create(string $logicName): RequestLogicInterface {
		switch ($logicName) {
			case 'aliasing':
				/** @var array<AliasedNodeInterface> $aliasedNodes */
				$aliasedNodes = $this->nodeSet->getNodesByInterface(AliasedNodeInterface::class);
				$fieldNames = $this->tableFieldInfo->getFieldNames();

				return new Aliasing($aliasedNodes, $fieldNames);
			case 'filtering':
				/** @var array<GroupFilter> $filterNodes */
				$filterNodes = $this->nodeSet->getNodesByClass(GroupFilter::class);
				/** @var QueryFilter $queryFilterNode */
				$queryFilterNode = array_values($this->nodeSet->getNodesByClass(QueryFilter::class))[0];
				$groupFieldInds = array_keys($this->nodeSet->getGroupFields());
				$lastGroupNodeInd = $groupFieldInds ? end($groupFieldInds) : -1;

				return new Filtering($filterNodes, $queryFilterNode, $lastGroupNodeInd);
			case 'ordering':
				/** @var array<Term> $orderedNodes */
				$orderedNodes = $this->nodeSet->getNodesByClass(Term::class);
				/** @var array<Metric> $metricNodes */
				$metricNodes = $this->nodeSet->getNodesByClass(Metric::class);
				/** @var array<GroupFilter> $groupFilterNodes */
				$groupFilterNodes = $this->nodeSet->getNodesByClass(GroupFilter::class);
				$groupExprNodeCount = sizeof($this->nodeSet->getNodesByClass(GroupExprNode::class));

				return new Ordering($orderedNodes, $metricNodes, $groupFilterNodes, $groupExprNodeCount);
			case 'field_detecting':
				/** @var array<AggNode> $aggNodes */
				$aggNodes = $this->nodeSet->getNodesByClass(AggNode::class);
				$argFieldNames = $this->nodeSet->getArgFields();

				return new FieldDetecting($aggNodes, $argFieldNames, $this->tableFieldInfo, $this->sphinxQLRequest);
			default:
				throw new \Exception("Unknown request logic name $logicName is passed");
		}
	}
}

<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Factory as RequestFactory;
// @phpcs:ignore
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\ConcurrentFilterProcessing\Processing
	as ConcurrentFilterProcessing;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Interfaces\ResponseLogicInterface;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Sorting\Metric\CalculatorFactory;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Sorting\Sorting;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\NodeSet;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\GroupFilter;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Histogram;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Metric;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Term;

/**
 * Creates node objects from Kibana request data
 */
class Factory extends RequestFactory {

	/**
	 * @param NodeSet $nodeSet
	 * @param CalculatorFactory $metricCalculatorFactory
	 * @param string $countField
	 */
	public function __construct(
		protected NodeSet $nodeSet,
		protected CalculatorFactory $metricCalculatorFactory,
		protected string $countField
	) {
	}

	/**
	 * @param string $logicName
	 * @return ResponseLogicInterface
	 * @throws \Exception
	 */
	public function create(string $logicName): ResponseLogicInterface {
		switch ($logicName) {
			case 'sorting':
				$groupFields = $this->nodeSet->getGroupFields();
				/** @var array<Term> $orderedNodes */
				$orderedNodes = $this->nodeSet->getNodesByClass(Term::class);
				/** @var array<Metric> $metricNodes */
				$metricNodes = $this->nodeSet->getNodesByClass(Metric::class);

				return new Sorting($groupFields, $orderedNodes, $metricNodes, $this->metricCalculatorFactory);
			case 'unmatched_filter_processing':
				/** @var array<GroupFilter> $filterNodes */
				$filterNodes = $this->nodeSet->getNodesByClass(GroupFilter::class);
				/** @var array<string> $groupFields */
				$groupFields = $this->nodeSet->getGroupFields();
				/** @var array<string> $metricFields */
				$metricFields = $this->nodeSet->getMetricFields();

				return new UnmatchedFilterProcessing($filterNodes, $groupFields, $metricFields, $this->countField);
			case 'concurrent_filter_processing':
				/** @var array<GroupFilter> $filterNodes */
				$filterNodes = $this->nodeSet->getNodesByClass(GroupFilter::class);

				return new ConcurrentFilterProcessing($filterNodes);
			case 'histogram_extending':
				/** @var array<int,Histogram> $histogramNodes */
				$histogramNodes = $this->nodeSet->getNodesByClass(Histogram::class);
				/** @var array<string> $metricFields */
				$metricFields = $this->nodeSet->getMetricFields();
				/** @var array<Term> $termNodes */
				$termNodes = $this->nodeSet->getNodesByClass(Term::class);

				return new HistogramExtending($histogramNodes, $metricFields, $termNodes, $this->countField);
			case 'disabled_metric_adding':
				/** @var array<Metric> $metricNodes */
				$metricNodes = $this->nodeSet->getNodesByClass(Metric::class);

				return new DisabledMetricAdding($metricNodes);
			default:
				throw new \Exception("Unknown response logic name $logicName is passed");
		}
	}
}

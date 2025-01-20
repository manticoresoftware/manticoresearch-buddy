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
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Metric;

/**
 *  Processes response data rows that must be filtered out by Kibana's filtering rules
 *  removing them or setting empty the values of their aggregation fields
 */
class DisabledMetricAdding extends BaseLogic {

	/** @var array<Metric> $disabledMetricNodes */
	protected array $disabledMetricNodes = [];

	/**
	 * @param array<Metric> $metricNodes
	 */
	public function __construct(protected array $metricNodes) {
	}

	/**
	 * @return bool
	 */
	public function isAvailable(): bool {
		$this->disabledMetricNodes = array_filter(
			$this->metricNodes,
			fn ($node) => $node->isDisabled()
		);
		return !!sizeof($this->disabledMetricNodes);
	}

	/**
	 * @return static
	 */
	public function apply(): static {
		$metricFields = array_map(
			fn ($node) => $node->getFieldAlias() ?: $node->getField(),
			$this->disabledMetricNodes
		);
		foreach (array_keys($this->responseRows) as $i) {
			foreach ($metricFields as $field) {
				$this->responseRows[$i][$field] = '';
			}
		}

		return $this;
	}
}

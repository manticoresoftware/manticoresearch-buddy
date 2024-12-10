<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Sorting\Metric;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Metric;

/**
 *  Creates a Calculator object for a given Metric node instance
 */
final class CalculatorFactory {

	/**
	 * @param string $countField
	 */
	public function __construct(private string $countField) {
	}

	/**
	 * @param Metric $metricNode
	 * @return Calculator
	 */
	public function createFromNode(Metric $metricNode): Calculator {
		return new Calculator($metricNode, $this->countField);
	}

	/**
	 * @return CountCalculator
	 */
	public function create(): CountCalculator {
		return new CountCalculator();
	}
}

<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Sorting\Metric;

/**
 *  Adds aggregated metric field values to data rows
 */
interface MetricUpdaterInterface extends MetricCalculatorInterface {

	/**
	 * @param int $updateRowInd
	 * @param string $updateField
	 * @param mixed $updateVal
	 * @return void
	 */
	public function addUpdate(int $updateRowInd, string $updateField, mixed $updateVal): void;

	/**
	 * @return array<int,array<mixed>>
	 */
	public function getUpdates(): array;
}

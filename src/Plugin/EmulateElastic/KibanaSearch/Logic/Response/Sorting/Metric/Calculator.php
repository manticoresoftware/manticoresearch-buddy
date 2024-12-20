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
 *  Adds aggregated metric field values to data rows
 */
final class Calculator implements MetricUpdaterInterface {

	/** @var array<int,array<string,mixed>> $updates */
	private array $updates = [];
	/** @var Operation $operation */
	private Operation $operation;
	/** @var string $func */
	private string $func;
	/** @var string $updateField */
	private string $updateField;

	/**
	 * @param Metric $metricNode
	 */
	public function __construct(Metric $metricNode, private string $countField) {
		$this->func = $metricNode->getField();
		$this->updateField = $metricNode->getKey();
	}

	/**
	 * @param array<int,array<string,mixed>> $sortRows
	 * @param string $sortField
	 * @return int|float|false
	 * @throws \Exception
	 */
	public function calc(array $sortRows, string $sortField): int|float|false {
		if (!$sortRows) {
			return false;
		}
		$this->detectOperation();
		$updateInd = array_key_first($sortRows);
		/** @var array<int|float> $metrics */
		$metrics = array_column($sortRows, $sortField);
		if ($this->operation === Operation::AVG) {
			/** @var array<int|float> $counts */
			$counts = array_column($sortRows, $this->countField);
		}
		$calcValue = match (true) {
			($this->operation === Operation::SUM) => array_sum($metrics),
			($this->operation === Operation::AVG) => $this->mult($metrics, $counts) / sizeof($metrics),
			($this->operation === Operation::MAX) => max($metrics),
			($this->operation === Operation::MIN) => min($metrics),
			default => throw new \Exception("Illegal operation {$this->operation->value} passed"),
		};
		$this->addUpdate($updateInd, $this->updateField, $calcValue);

		return $calcValue;
	}

	/**
	 * @param int $updateInd
	 * @param string $updateField
	 * @param mixed $updateValue
	 * @return void
	 */
	public function addUpdate(int $updateInd, string $updateField, mixed $updateValue): void {
		if (!array_key_exists($updateInd, $this->updates)) {
			$this->updates[$updateInd] = [];
		}
		$this->updates[$updateInd][$updateField] = $updateValue;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function getUpdates(): array {
		return $this->updates;
	}

	/**
	 * @param array<int|float> $arr1
	 * @param array<int|float> $arr2
	 * @return int|float
	 */
	private static function mult(array $arr1, array $arr2): int|float {
		$res = 0;
		foreach ($arr1 as $i => $v) {
			$res += $v * $arr2[$i];
		}
		return $res;
	}

	/**
	 * @return void
	 * @throws \Exception
	 */
	private function detectOperation(): void {
		if (isset($this->operation)) {
			return;
		}
		// We rely here on the unified format of aggregation function names in Manticore
		$operation = Operation::tryFrom(substr($this->func, 0, 3));
		if ($operation === null) {
			throw new \Exception("Unsupported metric function {$this->func} passed");
		}
		$this->operation = $operation;
	}
}

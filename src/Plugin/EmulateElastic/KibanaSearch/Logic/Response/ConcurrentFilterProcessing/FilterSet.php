<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\ConcurrentFilterProcessing;

/**
 * Represents a set of concurrent filters
 */
final class FilterSet {

	/** @var array<string> $fields */
	private array $fields = [];

	/**
	 * @param string $field
	 * @return self
	 */
	public function addField(string $field): self {
		$this->fields[] = $field;
		return $this;
	}

	/**
	 * @return array<string>
	 */
	public function getFields(): array {
		return $this->fields;
	}

	/**
	 * Checks if a data row contains multiple concurrent filters and, therefore, requires extra processing
	 *
	 * @param array<string,mixed> $row
	 * @return array<int,array<string,mixed>>|false
	 */
	public function check(array $row): array|false {
		$activeFilters = array_filter(
			$this->fields,
			// @phpstan-ignore-next-line
			fn ($filterField) => $row[$filterField]
		);
		if (sizeof($activeFilters) < 2) {
			return false;
		}

		return self::convertRowToSingleFilterOnes($row, $activeFilters);
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string> $activeFilters
	 * @return array<int,array<string,mixed>>
	 */
	private static function convertRowToSingleFilterOnes(array $row, array $activeFilters): array {
		$addRows = [];
		foreach ($activeFilters as $j => $filterField) {
			$newRow = $row;
			foreach ($activeFilters as $k => $filterField) {
				if ($j === $k) {
					continue;
				}
				$newRow[$filterField] = 0;
			}
			$addRows[] = $newRow;
		}

		return $addRows;
	}
}

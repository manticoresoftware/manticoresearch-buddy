<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Helpers\FilterExpression;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Interfaces\FilterNodeInterface;

/**
 *  Represents logical expressions used by filter nodes
 */
final class FilterExpression {

	const CMP_INVERSE_MAP = [
		'>' => '<=',
		'<' => '>=',
		'>=' => '<=',
		'<=' => '>=',
		'=' => '<>',
	];

	/** @var FilterNodeInterface $node */
	private FilterNodeInterface $node;
	/** @var array<string> $filterFields */
	private array $filterFields = [];

	/**
	 * @param array<string> $nonAggFields
	 */
	public function __construct(private array $nonAggFields) {
	}

	/**
	 * @param FilterNodeInterface $node
	 * @return void
	 */
	public function setFilterNode(FilterNodeInterface $node): void {
		$this->node = $node;
	}

	/**
	 * @return string
	 */
	public function build(): string {
		return $this->buildFromFilter($this->node->getFilter(), 'AND', false);
	}

	/**
	 * Converts data from a Kibana request's filter object to a logical expression
	 *
	 * @param array<mixed> $filter
	 * @param string $cond
	 * @param bool $inNotFilter
	 * @return string
	 */
	public function buildFromFilter(array $filter, string $cond, bool $inNotFilter): string {
		$expr = '';
		$subFilterCount = 0;
		foreach (array_keys($filter) as $filterKey) {
			switch ($filterKey) {
				case 'bool':
					/** @var array<mixed> $subFilter */
					$subFilter = $filter[$filterKey];
					return $this->buildFromFilter($subFilter, $cond, $inNotFilter);
				case 'filter':
				case 'must':
				case 'should':
				case 'must_not':
					/** @var array<array<mixed>> $subFilter */
					$subFilter = $filter[$filterKey];
					$subFilterCount++;
					$this->buildFromSubFilter($filterKey, $subFilter, $cond, $inNotFilter, $expr);
					break;
				case 'query_string':
					/** @var array{fields:array<string>,query:string} $subFilter */
					$subFilter = $filter[$filterKey];
					return $this->makeQueryStringExpr($subFilter, $inNotFilter);
				case 'match':
				case 'match_phrase':
					/** @var array<mixed> $subFilter */
					$subFilter = $filter[$filterKey];
					return $this->makeMatchExpr($subFilter, $inNotFilter);
				case 'range':
					/** @var array<mixed> $subFilter */
					$subFilter = $filter[$filterKey];
					return $this->makeRangeExpr($subFilter, $inNotFilter);
				case 'exists':
				case 'match_all':
					return '';
				default:
					break;
			}
		}

		return ($cond === 'OR' && $subFilterCount > 1) ? "($expr)" : $expr;
	}

	/**
	 * @return array<string>
	 */
	public function getFilterFields(): array {
		return $this->filterFields;
	}

	/**
	 * Processes nested filter objects from the Kibana filter object
	 *
	 * @param string $filterKey
	 * @param array<array<mixed>> $subFilter
	 * @param string $cond
	 * @param bool $inNotFilter
	 * @param string $expr
	 */
	protected function buildFromSubFilter(
		string $filterKey,
		array $subFilter,
		string $cond,
		bool $inNotFilter,
		string &$expr
	): void {
		$subInNotFilter = ($filterKey === 'must_not') ? !$inNotFilter : $inNotFilter;
		$subCond = ($filterKey === 'should') ? 'OR' : 'AND';
		if ($inNotFilter) {
			$subCond = ($subCond === 'OR') ? 'AND' : 'OR';
		}
		$subExpr = $this->buildSubExpr($subFilter, $subCond, $subInNotFilter);
		if (!$subExpr) {
			return;
		}
		$expr .= $expr ? " $cond $subExpr" : $subExpr;
	}

	/**
	 * @param array<array<mixed>> $filter
	 * @param string $subCond
	 * @param bool $inNotFilter
	 * @return string
	 */
	protected function buildSubExpr(array $filter, string $subCond, bool $inNotFilter): string {
		$subExpr = '';
		foreach ($filter as $subFilter) {
			$subRes = $this->buildFromFilter($subFilter, $subCond, $inNotFilter);
			if (!$subRes) {
				continue;
			}
			$subExpr .= $subExpr ? " $subCond $subRes" : $subRes;
		}
		if (!$subExpr) {
			return '';
		}
		if ($subCond === 'OR' && sizeof($filter) > 1) {
			$subExpr = "($subExpr)";
		}

		return $subExpr;
	}

	/**
	 * @param mixed $val
	 * @return void
	 */
	protected function quoteCmpValueIfNeeded(mixed &$val): void {
		if ($val !== '' && !is_string($val)) {
			return;
		}
		if (($val[0] === '"' and $val[-1] === '"') or ($val[0] === "'" and $val[-1] === "'")) {
			return;
		}
		$val = "'$val'";
	}

	/**
	 * Builds logical expression from the 'query_string' filter object
	 * making sure it's allowed for the given request field
	 *
	 * @param array{fields:array<string>,query:string} $subFilter
	 * @param bool $inNotFilter
	 * @return string
	 */
	protected function makeQueryStringExpr(array $subFilter, bool $inNotFilter): string {
		[
			'fields' => $fields,
			'query' => $query,
		] = $subFilter;
		array_push($this->filterFields, ...$fields);

		if (!array_intersect($fields, $this->nonAggFields)) {
			throw new \Exception('Operation is supported only for full-text fields');
		}
		if (!$this->node->isDirect()) {
			throw new \Exception('This type of filtering does not support wildcard queries');
		}
		$fieldExpr = sizeof($fields) > 1 ? '(' . implode(',', $fields) . ')' : $fields[0];
		if ($inNotFilter) {
			$query .= '!';
		}

		return "MATCH ('@$fieldExpr $query')";
	}

	/**
	 * Builds logical expression from the 'match' filter object
	 * making sure it's allowed for the given request field
	 *
	 * @param array<mixed> $subFilter
	 * @param bool $inNotFilter
	 * @return string
	 */
	protected function makeMatchExpr(array $subFilter, bool $inNotFilter): string {
		$mathCmpOp = '=';
		/** @var string $field */
		$field = array_key_first($subFilter);
		$this->filterFields[] = $field;
		$isNonAggField = in_array($field, $this->nonAggFields);
		if ($isNonAggField && !$this->node->isDirect()) {
			throw new \Exception('Filter grouping is supported only for attribute fields');
		}
		/** @var string $fieldVal */
		$fieldVal = $subFilter[$field];
		if ($isNonAggField) {
			if ($inNotFilter) {
				$fieldVal .= '!';
			}
			return "MATCH ('@$field $fieldVal')";
		}
		$this->quoteCmpValueIfNeeded($fieldVal);
		if ($inNotFilter) {
			$mathCmpOp  = self::CMP_INVERSE_MAP[$mathCmpOp];
		}

		return "$field $mathCmpOp $fieldVal";
	}

	/**
	 * Builds logical expression from the 'range' filter object
	 * making sure it's allowed for the given request field
	 *
	 * @param array<mixed> $subFilter
	 * @param bool $inNotFilter
	 * @return string
	 */
	protected function makeRangeExpr(array $subFilter, bool $inNotFilter): string {
		/** @var string $field */
		$field = array_key_first($subFilter);
		$this->filterFields[] = $field;
		if (in_array($field, $this->nonAggFields)) {
			throw new \Exception('Range filtering is supported only for attribute fields');
		}
		/** @var array<string,string> $fieldObj */
		$fieldObj = $subFilter[$field];
		$expr = '';
		foreach (array_keys($fieldObj) as $cmpOp) {
			$fieldVal = $fieldObj[$cmpOp];
			if (is_string($fieldVal)) {
				$fieldVal = strtotime($fieldVal);
			}
			$mathCmpOp = match ($cmpOp) {
				'gt' => '>',
				'lt' => '<',
				'gte' => '>=',
				'lte' => '<=',
				default => '',
			};
			if (!$mathCmpOp) {
				continue;
			}
			$subCond = 'AND';
			if ($inNotFilter) {
				$mathCmpOp  = self::CMP_INVERSE_MAP[$mathCmpOp];
				$subCond = 'OR';
			}
			$subExpr = "$field $mathCmpOp $fieldVal";
			$expr .= $expr ? " $subCond $subExpr" : $subExpr;
		}

		return $expr;
	}
}

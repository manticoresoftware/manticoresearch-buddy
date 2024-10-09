<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode;

/**
 *  Date range node of Kibana search request
 */
abstract class BaseRange extends GroupExprNode {

	const EXPR_FUNC = '';

	/** @var string $aliasedFieldExpr */
	protected string $aliasedFieldExpr = '';
	/** @var string $argField */
	protected string $argField = '';
	/** @var array<int,array{from:string|float,to:string|float}> $ranges */
	protected array $ranges;

	/**
	 * @return int
	 */
	public function getRangeCount(): int {
		return sizeof($this->ranges);
	}

	/**
	 * @return void
	 */
	protected function makeFieldExpr(): void {
		$rangeExprs = [];
		foreach ($this->ranges as $range) {
			$rangeExpr = '';
			if ($range['from']) {
				$rangeExpr = "range_from={$range['from']}";
			}
			if ($range['to']) {
				$rangeExpr .= ($rangeExpr ? ',' : '') . "range_to={$range['to']}";
			}
			if (in_array($rangeExpr, $rangeExprs)) {
				// There's no need to include the same range in the request multiple times
				continue;
			}
			$rangeExprs[] = "{{$rangeExpr}}";
		}
		$this->fieldExpr = static::EXPR_FUNC . "({$this->argField}," . implode(',', $rangeExprs) . ')';
	}
}

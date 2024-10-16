<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Helpers\FilterExpression;

/**
 *  Creates logical expression objects to be used for filter nodes
 */
final class Factory {

	/**
	 * @param array<string> $nonAggFields
	 */
	public function __construct(private array $nonAggFields) {
	}

	/**
	 * @return FilterExpression
	 */
	public function create() {
		return new FilterExpression($this->nonAggFields);
	}
}

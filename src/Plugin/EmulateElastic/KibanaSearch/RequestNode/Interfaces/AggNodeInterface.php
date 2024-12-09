<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Interfaces;

/**
 *  Handles Kibana request nodes which contain request aggregation
 *  i.e., these nodes will correspond to the nodes of the further response
 */
interface AggNodeInterface {

	/** @return string */
	public function getField(): string;

	/**
	 * @param array<string,mixed> $responseNode
	 * @param array<string, mixed> $dataRow
	 * @param string $nextNodeKey
	 * @return array<string|int>|false
	 */
	public function fillInResponse(array &$responseNode, array $dataRow, string $nextNodeKey): array|false;
}

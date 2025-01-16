<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Interfaces\ResponseLogicInterface;

/**
 *  Processes search responses from Manticore setting the values of aggregation fields empty for the rows
 *  that must be filtered out by Kibana's filtering rules
 */
abstract class BaseLogic implements ResponseLogicInterface {

	/** @var array<int,array<string,mixed>> $responseRows */
	protected array $responseRows = [];

	/**
	 * @param array<int,array<string,mixed>> $responseRows
	 * @return static
	 */
	public function setResponseRows(array $responseRows): static {
		$this->responseRows = $responseRows;
		return $this;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function getResponseRows(): array {
		return $this->responseRows;
	}
}

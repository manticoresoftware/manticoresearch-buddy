<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Executor as RequestExecutor;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Interfaces\ResponseLogicInterface;

/**
 * Manages the execution of response logics with the given response data
 */
class Executor extends RequestExecutor {

	const LOGIC_NAMES = [
		'sorting',
		'unmatched_filter_processing',
		'concurrent_filter_processing',
		'histogram_extending',
	];

	/** @var array<ResponseLogicInterface> $logics */
	protected array $logics;

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

	/**
	 * @return bool
	 */
	public function execute(): bool {
		$availableLogics = array_filter(
			$this->logics,
			fn ($logic) => $logic->isAvailable()
		);

		foreach ($availableLogics as $logic) {
			$this->responseRows = $logic
				->setResponseRows($this->responseRows)
				->apply()
				->getResponseRows();
		}

		return true;
	}
}

<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Interfaces\FailableLogicInterface;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Interfaces\RequestLogicInterface;

/**
 * Runs the execution of provided logics
 */
class Executor {

	const LOGIC_NAMES = ['aliasing', 'filtering', 'ordering'];

	/** @var array<RequestLogicInterface> $logics */
	protected array $logics = [];

	/**
	 * @param Factory $logicFactory
	 */
	public function __construct(protected Factory $logicFactory) {
	}

	/**
	 * @return static
	 */
	public function init(): static {
		$this->logics = array_map(
			fn ($logicName) => $this->logicFactory->create($logicName),
			static::LOGIC_NAMES
		);

		return $this;
	}

	/**
	 * @return bool
	 */
	public function execute(): bool {
		foreach ($this->logics as $logic) {
			$logic->apply();
			// Checking if the current logic can be applied here
			if (!($logic instanceof FailableLogicInterface)) {
				continue;
			}
			if ($logic->isFailed()) {
				return false;
			}
		}
		return true;
	}
}

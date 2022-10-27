<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Trait;

use \RuntimeException;

trait CustomErrorTrait {

	/**
	 * @param string $errorMsg
	 * @param bool $noHandler
	 * @return void
	 * @throws RuntimeException
	 */
	public function error(string $errorMsg, bool $noHandler = false): void {
		if ($noHandler === true || !property_exists($this, 'exceptionHandler')
			|| !method_exists($this->exceptionHandler, 'throw')) {
			throw new RuntimeException($errorMsg);
		}
		$this->exceptionHandler->throw($errorMsg);
	}

}

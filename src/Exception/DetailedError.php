<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Exception;

use Manticoresearch\Buddy\Interface\CustomErrorInterface;
use RuntimeException;

abstract class DetailedError implements CustomErrorInterface {

	const ERROR_MSG = '';

	/**
	 * @param string|null $message
	 * @return void
	 */
	public function throw(string $message = null): void {
		$message = static::ERROR_MSG . ($message ?? '');
		throw new RuntimeException($message);
	}

}

<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Exception;

use RuntimeException;
use Throwable;

abstract class DetailedError extends RuntimeException {

	const ERROR_MSG = '';
	const NO_DETAILS_MSG = 'No details specified';

	final public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null) {
		$basicErrorMsg = (static::ERROR_MSG !== '') ? static::ERROR_MSG . ': ' : '';
		$message = $basicErrorMsg . ($message !== '' ? $message : self::NO_DETAILS_MSG);
		parent::__construct($message, $code, $previous);
	}

}

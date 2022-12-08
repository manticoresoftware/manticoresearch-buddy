<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Exception;

use Exception;
use Throwable;

final class BuddyError extends Exception {
	/**
	 * @param Throwable $error
	 * @param string $originalError
	 * @return void
	 */
	public function __construct(protected Throwable $error, protected string $originalError = '') {
	}

	/**
	 * @param Throwable $error
	 * @param string $originalError
	 * @return self
	 */
	public static function from(Throwable $error, string $originalError = ''): self {
		return new self($error, $originalError);
	}

	/**
	 * Client error message, that we return to the manticore to return to client
	 *
	 * @return string
	 */
	public function getResponseError(): string {
		return $this->originalError;
	}
}

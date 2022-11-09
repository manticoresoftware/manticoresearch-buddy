<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Interface;

interface BuddyResponseInterface {
	/**
	 * @param string $message
	 * @param string $error
	 * @param ?class-string<\RuntimeException> $ex
	 * @return string
	 */
	public static function buildResponse(string $message = '', string $error = '', ?string $ex = null): string;
}

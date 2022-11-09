<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Interface;

// @codingStandardsIgnoreStart
use Manticoresearch\Buddy\Exception\BuddyLocatorError;
// @codingStandardsIgnoreEnd

interface BuddyLocatorInterface {
	/**
	 * @param string $interface
	 * @param ?array<mixed> $constructArgs
	 * @param ?object $parentObj
	 * @param ?string $setter
	 * @param ?array<mixed> $setterArgs
	 * @return object
	 * @throws BuddyLocatorError
	 */
	public function getByInterface(
		string $interface,
		?array $constructArgs = [],
		?object $parentObj = null,
		?string $setter = null,
		?array $setterArgs = []
	): object;
}

<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Exception\BuddyLocatorError;
use Manticoresearch\Buddy\Interface\BuddyLocatorInterface;
use Manticoresearch\Buddy\Interface\ManticoreHTTPClientInterface;
use Manticoresearch\Buddy\Interface\ManticoreResponseBuilderInterface;
use Manticoresearch\Buddy\Interface\QueryParserLoaderInterface;
use Manticoresearch\Buddy\Interface\StatementInterface;

class BuddyLocator implements BuddyLocatorInterface {
	/**
	 * @param string $interface
	 * @return string
	 * @throws BuddyLocatorError
	 */
	protected function getClassByInterface(string $interface): string {
		return match ($interface) {
			QueryParserLoaderInterface::class => QueryParserLoader::class,
			ManticoreHTTPClientInterface::class => ManticoreHTTPClient::class,
			ManticoreResponseBuilderInterface::class => ManticoreResponseBuilder::class,
			StatementInterface::class => ManticoreStatement::class,
			default => throw new BuddyLocatorError("Unsupported interface $interface passed"),
		};
	}

// 	/**
// 	 * @param string $interface
// 	 * @param array<mixed> $args
// 	 * @return object
// 	 */
// 	public function getByInterface(string $interface, array $args = []): object {
// 		$cls = $this->getClassByInterface($interface);
// 		return new $cls(...$args);
// 	}

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
	): object {
		$cls = $this->getClassByInterface($interface);
		$obj = new $cls(...$constructArgs);
		if (!$obj instanceof $interface) {
			throw new BuddyLocatorError("located resource is not $interface");
		}
		if (!(isset($parentObj, $setter))) {
			return $obj;
		}
		try {
			if (method_exists($parentObj, $setter)) {
				$parentObj->$setter($obj, ...$setterArgs);
			} elseif (property_exists($parentObj, $setter)) {
				$parentObj->$setter = $obj;
			}
			return $obj;
		} catch (\Throwable $e) {
			throw new BuddyLocatorError($e->getMessage());
		}
	}

}

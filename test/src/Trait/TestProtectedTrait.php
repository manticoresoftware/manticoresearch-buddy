<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Trait;

trait TestProtectedTrait {

	/**
	 * @param class-string|object $classInstance
	 * @param string $methodName
	 * @param array<mixed> $args
	 * @return mixed
	 */
	public static function invokeMethod(mixed $classInstance, string $methodName, array $args = []): mixed {
		$class = new \ReflectionClass($classInstance);
		$method = $class->getMethod($methodName);
		$method->setAccessible(true);
		$ref = gettype($classInstance) === 'string' ? null : $classInstance;
		return $method->invokeArgs($ref, $args);
	}

}

<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Trait;

use Manticoresearch\Buddy\Core\Error\GenericError;

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

	/**
	 * @param class-string|object $classInstance
	 * @param string $methodName
	 * @param array<mixed> $args
	 * @return array{0:string,1:string}
	 */
	public static function getExceptionInfo(mixed $classInstance, string $methodName, array $args = []): array {
		$exCls = $exMsg = '';
		try {
			$res = self::invokeMethod($classInstance, $methodName, $args);
		} catch (GenericError $e) {
			$exCls = $e::class;
			echo 'error ' . (string)$e->hasResponseError();
			print_r($e);
			$customError = $e->getResponseError('');
			$exMsg = $customError ?: $e->getMessage();
		}

		return [$exCls, $exMsg];
	}
}

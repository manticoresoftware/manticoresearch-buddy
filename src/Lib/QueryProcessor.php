<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Exception;
use Manticoresearch\Buddy\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Network\Request;
use Psr\Container\ContainerInterface;

class QueryProcessor {
	/** @var string */
	protected const NAMESPACE_PREFIX = __NAMESPACE__ . '\\';

	/** @var ContainerInterface */
	// We set this on initialization (init.php) so we are sure we have it in class
	protected static ContainerInterface $container;

	/**
	 * Setter for container property
	 *
	 * @param ContainerInterface $container
	 *  The container object to resolve the executor's dependencies in case such exist
	 * @return void
	 *  The CommandExecutorInterface to execute to process the final query
	 */
	public static function setContainer(ContainerInterface $container): void {
		self::$container = $container;
	}

	/**
	 * This is the main entry point to start parsing and processing query
	 *
	 * @param Request $request
	 *  The request struct to process
	 * @return CommandExecutorInterface
	 *  The CommandExecutorInterface to execute to process the final query
	 */
	public static function process(Request $request): CommandExecutorInterface {
		$prefix = static::extractPrefixFromQuery($request->query);
		$requestType = "{$prefix}Request";
		$requestClassName = static::NAMESPACE_PREFIX . $requestType;
		$commandRequest = $requestClassName::fromNetworkRequest($request);

		$executorType = "{$prefix}Executor";
		$executorClassName = static::NAMESPACE_PREFIX . $executorType;
		/** @var \Manticoresearch\Buddy\Interface\CommandExecutorInterface */
		$executor = new $executorClassName($commandRequest);
		foreach ($executor->getProps() as $prop) {
			$executor->{'set' . ucfirst($prop)}(static::getObjFromContainer($prop));
		}
		/** @var CommandExecutorInterface */
		return $executor;
	}

	/**
	 * Retrieve object from the DI container
	 *
	 * @param string $objName
	 * @return object
	 */
	protected static function getObjFromContainer(string $objName): object {
		if (!self::$container->has($objName)) {
			throw new Exception("Failed to find '$objName' in container");
		}

		/** @var object */
		return self::$container->get($objName);
	}

	/**
	 * This method extracts all supported prefixes from input query
	 * that buddy able to handle
	 *
	 * @param string $query
	 * @return string
	 */
	public static function extractPrefixFromQuery(string $query): string {
		$queryLowercase = strtolower($query);
		return match (true) {
			str_starts_with($queryLowercase, 'insert into') => 'InsertQuery',
			str_starts_with($queryLowercase, 'show queries') => 'ShowQueries',
			str_starts_with($queryLowercase, 'backup') => 'Backup',
			default => throw new SQLQueryCommandNotSupported("Failed to handle query: $query"),
		};
	}
}

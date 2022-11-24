<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Exception\SQLQueryCommandMissing;
use Manticoresearch\Buddy\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Interface\CommandRequestInterface;
use Manticoresearch\Buddy\Network\Request;
use Psr\Container\ContainerInterface;

class QueryProcessor {

	/** @var string DEFAULT_COMMAND */
	final public const DEFAULT_COMMAND = 'ERROR QUERY';

	/** @var array<string> CUSTOM_COMMAND_TYPES */
	public const CUSTOM_COMMANDS = ['BACKUP'];

	/** @var string NAMESPACE_PREFIX */
	protected const NAMESPACE_PREFIX = __NAMESPACE__ . '\\';

	/** @var ?ContainerInterface $container */
	protected static ?ContainerInterface $container = null;

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
		$query = trim($request->query); // Fix spaces around
		$command = strtok($query, ' ');
		if (false === $command) {
			throw new SQLQueryCommandMissing("Command missing in SQL query '$query'");
		}
		$command = strtoupper($command); // For consistency uppercase
		if (!in_array($command, static::CUSTOM_COMMANDS)) {
			$command = self::DEFAULT_COMMAND;
		}

		// And now parse depending on command and return executor
		$executor = self::getExecutorByCommand($command, $request);
		if (!isset($executor)) {
			throw new SQLQueryCommandNotSupported("Command '$command' is not supported");
		}

		return $executor;
	}

	/**
	 * @param string $objType
	 * @param ?array<string,mixed> $initProps
	 * @return mixed
	 */
	protected static function getObjFromContainer(string $objType, ?array $initProps): mixed {
		if (!isset(self::$container) || !self::$container->has($objType)) {
			return null;
		}

		$containerObj = self::$container->get($objType);
		if (!isset($initProps)) {
			return $containerObj;
		}

		foreach ($initProps as $k => $v) {
			$containerObj->$k = $v;
		}
		return $containerObj;
	}

	/**
	 * @param string $command
	 * @param Request $request
	 * @return ?CommandExecutorInterface
	 */
	protected static function getExecutorByCommand(string $command, Request $request): ?CommandExecutorInterface {
		// Handle possible multi-words command
		$prefix = array_reduce(
			explode(' ', $command),
			fn ($res, $word) => $res . ucfirst(strtolower($word)),
			''
		);

		$requestType = "{$prefix}Request";
		$commandRequest = self::getObjFromContainer($requestType, ['request' => $request]);
		if (!isset($commandRequest) || !($commandRequest instanceof CommandRequestInterface)) {
			$requestClassName = static::NAMESPACE_PREFIX . $requestType;
			if (!class_exists($requestClassName)) {
				return null;
			}
			$commandRequest = $requestClassName::fromNetworkRequest($request);
		}

		$executorType = "{$prefix}Executor";
		$commandExecutor = self::getObjFromContainer($executorType, ['request' => $commandRequest]);
		if (isset($commandExecutor) && $commandExecutor instanceof CommandExecutorInterface) {
			return $commandExecutor;
		}

		$executorClassName = static::NAMESPACE_PREFIX . $executorType;
		if (!class_exists($executorClassName)) {
			return null;
		}
		/** @var \Manticoresearch\Buddy\Interface\CommandExecutorInterface */
		return new $executorClassName($commandRequest);
	}

}

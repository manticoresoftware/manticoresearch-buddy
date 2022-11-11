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
use Manticoresearch\Buddy\Network\Request;

class QueryProcessor {

	/** @var string DEFAULT_COMMAND */
	final public const DEFAULT_COMMAND = 'ERROR QUERY';

	/** @var array<string> CUSTOM_COMMAND_TYPES */
	public const CUSTOM_COMMANDS = ['BACKUP'];

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
		// Handle possible multi-words command
		$prefix = array_reduce(
			explode(' ', $command),
			fn ($res, $word) => $res . ucfirst(strtolower($word)),
			''
		);

		$executorClassName = "\\Manticoresearch\\Buddy\\Lib\\{$prefix}Executor";
		$requestClassName = "\\Manticoresearch\\Buddy\\Lib\\{$prefix}Request";
		if (!class_exists($executorClassName) || !class_exists($requestClassName)) {
			throw new SQLQueryCommandNotSupported("Command '$command' is not supported");
		}

		/** @var \Manticoresearch\Buddy\Interface\CommandExecutorInterface */
		return new $executorClassName(
			$requestClassName::fromNetworkRequest($request)
		);
	}
}

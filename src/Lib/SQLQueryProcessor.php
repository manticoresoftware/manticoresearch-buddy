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

/**
 * This is the main processor that handle query and find what to do next
 * @author Manticore Software LTD (https://manticoresearch.com)
 */
class SQLQueryProcessor {
  /**
   * This is the main entry point to start parsing and processing query
   *
   * @param string $query
   *  The SQL query to parse and return the function to process
   * @return CommandExecutorInterface
   *  The CommandExecutorInterface to execute to process the final query
   * @throws SQLQueryCommandMissing
   */
	public static function process(string $query): CommandExecutorInterface {
	  // DO prevalidation first
		$query = trim($query); // Fix spaces around
		$command = strtok($query, ' ');
		if (false === $command) {
			throw new SQLQueryCommandMissing();
		}
		$command = strtoupper($command); // For consistency uppercase

	  // And now parse depending on command and return executor
		return static::processCommand(
			$command,
			trim(substr($query, strlen($command)))
		);
	}

  /**
   * Process validated query with required command and return executor
   *
   * @param string $command
   * @param string $query
   * @return CommandExecutorInterface
   * @throws SQLQueryCommandNotSupported
   */
	public static function processCommand(string $command, string $query): CommandExecutorInterface {
		$prefix = ucfirst(strtolower($command));

		$executorClassName = "\\Manticoresearch\\Buddy\\Lib\\{$prefix}Executor";
		$requestClassName = "\\Manticoresearch\\Buddy\\Lib\\{$prefix}Request";
		if (!class_exists($executorClassName) || !class_exists($requestClassName)) {
			throw new SQLQueryCommandNotSupported();
		}

	  /** @var \Manticoresearch\Buddy\Interface\CommandExecutorInterface */
		return new $executorClassName(
			$requestClassName::fromQuery($query)
		);
	}
}

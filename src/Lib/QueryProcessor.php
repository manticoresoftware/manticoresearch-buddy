<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Enum\MntEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\SQLQueryCommandMissing;
use Manticoresearch\Buddy\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Exception\UnvalidMntRequestError;
use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use ValueError;

class QueryProcessor {

	/** @var array<string,string> MNT_REQUEST_FIELD_MAP */
	final public const MNT_REQUEST_FIELD_MAP = [
		'type' => 'origMsg',
		'message' => 'query',
		'reqest_type' => 'format',
	];

	/** @var string DEFAULT_COMMAND */
	final public const DEFAULT_COMMAND = 'ERROR QUERY';

	/** @var array<string> CUSTOM_COMMAND_TYPES */
	public const CUSTOM_COMMANDS = ['BACKUP'];

	/** @var array{origMsg:string,query:string,format:RequestFormat,endpoint:MntEndpoint} $mntRequest */
	protected static array $mntRequest;

	/** @var array<mixed> $mtest */
	public static array $mtest;

	/**
	 * This is the main entry point to start parsing and processing query
	 *
	 * @param string $query
	 *  The query to parse and return the function to process
	 * @return CommandExecutorInterface
	 *  The CommandExecutorInterface to execute to process the final query
	 */
	public static function process(string $query): CommandExecutorInterface {
		// DO prevalidation first
		self::validateMntQuery($query);

		$command = self::makeCommand();
		// And now parse depending on command and return executor
		return static::processCommand($command);
	}

	/**
	 * Process validated query with required command and return executor
	 *
	 * @param string $command
	 * @param ?string $query
	 * @return CommandExecutorInterface
	 * @throws SQLQueryCommandNotSupported
	 */
	public static function processCommand(
		string $command,
		string $query = null
	): CommandExecutorInterface {

		if (isset($query)) {
			self::validateMntQuery($query);
		}

		// Handle possible multi-words command
		$prefix = array_reduce(
			explode(' ', $command),
			function ($res, $word) {
				return $res . ucfirst(strtolower($word));
			},
			''
		);

		$executorClassName = "\\Manticoresearch\\Buddy\\Lib\\{$prefix}Executor";
		$requestClassName = "\\Manticoresearch\\Buddy\\Lib\\{$prefix}Request";
		if (!class_exists($executorClassName) || !class_exists($requestClassName)) {
			throw new SQLQueryCommandNotSupported("Command '$command' is not supported");
		}

		/** @var \Manticoresearch\Buddy\Interface\CommandExecutorInterface */
		return new $executorClassName(
			$requestClassName::fromMntRequest(self::$mntRequest)
		);
	}

	/**
	 * @param array<string,string> $mntRequest
	 * @return void
	 * @throws UnvalidMntRequestError
	 */
	protected static function validateMntRequest(array $mntRequest): void {
		foreach (array_keys(self::MNT_REQUEST_FIELD_MAP) as $k) {
			if (!array_key_exists($k, $mntRequest)) {
				throw new UnvalidMntRequestError("Mandatory field '$k' is missing");
			}
			if (!is_string($mntRequest[$k])) {
				throw new UnvalidMntRequestError("Field '$k' must be a string");
			}
		}
		// Checking if request format and endpoint are supported
		try {
			$endpoint = MntEndpoint::from($mntRequest['endpoint']);
		} catch (ValueError) {
			throw new UnvalidMntRequestError("Unknown request endpoint '{$mntRequest['endpoint']}'");
		}
		try {
			$requestType = RequestFormat::from($mntRequest['reqest_type']);
		} catch (\Throwable) {
			throw new UnvalidMntRequestError("Unknown request type '{$mntRequest['reqest_type']}'");
		}
		$mntRequest['reqest_type'] = $requestType;
		$mntRequest['endpoint'] = $endpoint;

		// Change original request field names to more informative ones
		foreach (self::MNT_REQUEST_FIELD_MAP as $k => $v) {
			$mntRequest[$v] = $mntRequest[$k];
		}

		/**
		 * @var array{origMsg:string,query:string,format:RequestFormat,endpoint:MntEndpoint} $mntRequest
		 */
		self::$mntRequest = $mntRequest;
	}

	/**
	 * @param string $query
	 * @return void
	 * @throws UnvalidMntRequestError
	 */
	protected static function validateMntQuery(string $query): void {
		if ($query === '') {
			throw new UnvalidMntRequestError('Query is missing');
		}
		$reqBodyPos = strpos($query, '{');
		if ($reqBodyPos === false) {
			throw new UnvalidMntRequestError("Request body is missing in query '{$query}'");
		}
		$query = substr($query, $reqBodyPos);
		$request = json_decode($query, true);
		if (!is_array($request)) {
			throw new UnvalidMntRequestError("Unvalid request body '{$query}' is passed");
		}

		self::validateMntRequest($request);
	}

	/**
	 * @return string
	 * @throws SQLQueryCommandMissing
	 */
	protected static function makeCommand(): string {
		$query = trim(self::$mntRequest['query']); // Fix spaces around
		$command = strtok($query, ' ');
		if (false === $command) {
			throw new SQLQueryCommandMissing("Command missing in SQL query '$query'");
		}
		$command = strtoupper($command); // For consistency uppercase
		if (!in_array($command, static::CUSTOM_COMMANDS)) {
			$command = self::DEFAULT_COMMAND;
		}

		return $command;
	}

}

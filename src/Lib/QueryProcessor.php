<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Exception;
use Manticoresearch\Buddy\Enum\Command;
use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Exception\CommandNotAllowed;
use Manticoresearch\Buddy\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use Manticoresearch\Buddy\Network\ManticoreClient\Settings as ManticoreSettings;
use Manticoresearch\Buddy\Network\Request;
use Psr\Container\ContainerInterface;

class QueryProcessor {
  /** @var string */
	protected const NAMESPACE_PREFIX = 'Manticoresearch\\Buddy\\';

  /** @var ContainerInterface */
  // We set this on initialization (init.php) so we are sure we have it in class
	protected static ContainerInterface $container;

	/** @var ManticoreSettings */
	protected static ManticoreSettings $settings;

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
   * @throws CommandNotAllowed
   */
	public static function process(Request $request): CommandExecutorInterface {
		if (!isset(static::$settings)) {
			static::init();
		}
		$command = static::extractCommandFromRequest($request);
		if (!self::isCommandAllowed($command)) {
			throw new CommandNotAllowed("Request handling is disabled: $request->payload");
		}
		$commandPrefix = $command->value;
		debug("[$request->id] Executor: $commandPrefix");
		buddy_metric(camelcase_to_underscore($commandPrefix), 1);
		$requestClassName = static::NAMESPACE_PREFIX . "{$commandPrefix}\\Request";
		$commandRequest = $requestClassName::fromNetworkRequest($request);
		$commandRequest->setManticoreSettings(static::$settings);
		debug("[$request->id] Command request: {$commandPrefix}\\Request " . json_encode($commandRequest));
		$executorClassName = static::NAMESPACE_PREFIX . "{$commandPrefix}\\Executor";
	  /** @var \Manticoresearch\Buddy\Interface\CommandExecutorInterface */
		$executor = new $executorClassName($commandRequest);
		foreach ($executor->getProps() as $prop) {
			$executor->{'set' . ucfirst($prop)}(static::getObjFromContainer($prop));
		}
	  /** @var CommandExecutorInterface */
		return $executor;
	}

  /**
   * Load show settings response and setup things on first request
   *
   * @return void
   */
	protected static function init(): void {
	  /** @var HTTPClient */
		$manticoreClient = static::getObjFromContainer('manticoreClient');
		$resp = $manticoreClient->sendRequest('SHOW SETTINGS');
	  /** @var array{0:array{columns:array<mixed>,data:array{Setting_name:string,Value:string}}} */
		$data = (array)json_decode($resp->getBody(), true);
		$settings = [];
		foreach ($data[0]['data'] as ['Setting_name' => $key, 'Value' => $value]) {
			$settings[$key] = $value;
			if ($key !== 'configuration_file') {
				continue;
			}

			debug("using config file = '$value'");
			putenv("SEARCHD_CONFIG={$value}");
		}
		static::$settings = ManticoreSettings::fromArray($settings);
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
   * Check if a command is currently supported by Buddy and daemon
   *
   * @param Command $command
   * @return bool
   */
	protected static function isCommandAllowed(Command $command): bool {
		return match (true) {
			($command === Command::Insert && !self::$settings->searchdAutoSchema) => false,
			default => true,
		};
	}

  /**
   * This method extracts all supported prefixes from input query
   * that buddy able to handle
   *
   * @param Request $request
   * @return Command
   * @throws SQLQueryCommandNotSupported
   */
	public static function extractCommandFromRequest(Request $request): Command {
		$queryLowercase = strtolower($request->payload);
		$queryNoSpaces = str_replace(' ', '', $queryLowercase);

		$isInsertSQLQuery = match ($request->endpointBundle) {
			ManticoreEndpoint::Sql, ManticoreEndpoint::Cli, ManticoreEndpoint::CliJson => str_starts_with(
				$queryLowercase, 'insert into'
			),
			default => false,
		};
		$isInsertHTTPQuery = match ($request->endpointBundle) {
			ManticoreEndpoint::Insert => true,
			ManticoreEndpoint::Bulk => str_starts_with($queryNoSpaces, '{"insert"')
			|| str_starts_with($queryNoSpaces, '{"index"'),
			default => false,
		};
		$isInsertError = str_contains($request->error, 'no such index')
			|| preg_match('/table (.*?) absent/', $request->error);

		return match (true) {
			$queryLowercase === '',
				str_starts_with($queryLowercase, 'set'),
				str_starts_with($queryLowercase, 'create database') => Command::EmptyQuery,
			($isInsertError && ($isInsertSQLQuery || $isInsertHTTPQuery)) => Command::Insert,
			str_starts_with($queryLowercase, 'show queries') => Command::ShowQueries,
			str_starts_with($queryLowercase, 'backup') => Command::Backup,
			str_starts_with($queryLowercase, 'show full tables') => Command::ShowFullTables,
			str_starts_with($queryLowercase, 'test') => Command::Test,
			($request->endpointBundle === ManticoreEndpoint::Cli) => Command::CliTable,
			str_starts_with($queryLowercase, 'lock tables') => Command::LockTables,
			str_starts_with($queryLowercase, 'unlock tables') => Command::UnlockTables,
			str_starts_with($queryLowercase, 'select') => Command::Select,
			str_starts_with($queryLowercase, 'show fields') => Command::ShowFields,
			default => throw new SQLQueryCommandNotSupported("Failed to handle query: $request->payload"),
		};
	}
}

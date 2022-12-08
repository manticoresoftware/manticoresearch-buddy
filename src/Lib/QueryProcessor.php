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
use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use Manticoresearch\Buddy\Network\Request;
use Psr\Container\ContainerInterface;

class QueryProcessor {
	/** @var string */
	protected const NAMESPACE_PREFIX = 'Manticoresearch\\Buddy\\';

	/** @var ContainerInterface */
	// We set this on initialization (init.php) so we are sure we have it in class
	protected static ContainerInterface $container;

	/** @var bool */
	protected static bool $inited = false;

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
		if (!static::$inited) {
			static::init();
		}
		$prefix = static::extractPrefixFromRequest($request);
		debug("[$request->id] Executor: $prefix");
		buddy_metric(camelcase_to_underscore($prefix), 1);
		$requestClassName = static::NAMESPACE_PREFIX . "{$prefix}\\Request";
		$commandRequest = $requestClassName::fromNetworkRequest($request);
		debug("[$request->id] Command request: {$prefix}\\Request " . json_encode($commandRequest));
		$executorClassName = static::NAMESPACE_PREFIX . "{$prefix}\\Executor";
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
		foreach ($data[0]['data'] as ['Setting_name' => $key, 'Value' => $value]) {
			if ($key !== 'configuration_file') {
				continue;
			}

			debug("using config file = '$value'");
			putenv("SEARCHD_CONFIG={$value}");
		}

		static::$inited = true;
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
	 * @param Request $request
	 * @return string
	 */
	public static function extractPrefixFromRequest(Request $request): string {
		$queryLowercase = strtolower($request->payload);
		$isInsertSQLQuery = in_array($request->endpoint, [ManticoreEndpoint::Sql, ManticoreEndpoint::Cli])
			&& str_starts_with($queryLowercase, 'insert into');
		$isInsertHTTPQuery = ($request->endpoint === ManticoreEndpoint::Insert)
			|| ($request->endpoint === ManticoreEndpoint::Bulk
				&& str_starts_with(str_replace(' ', '', $queryLowercase), '{"insert"')
		);
		$isInsertError = preg_match('/index (.*?) absent/', $request->error);
		file_put_contents('/tmp/test.txt', "test $isInsertSQLQuery, $isInsertError\n", FILE_APPEND);
		return match (true) {
			($isInsertError && ($isInsertSQLQuery || $isInsertHTTPQuery)) => 'InsertQuery',
			str_starts_with($queryLowercase, 'show queries') => 'ShowQueries',
			str_starts_with($queryLowercase, 'backup') => 'Backup',
			default => throw new SQLQueryCommandNotSupported("Failed to handle query: $request->payload"),
		};
	}
}

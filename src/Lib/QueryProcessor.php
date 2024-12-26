<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Lib;

use Manticoresearch\Buddy\Base\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings as ManticoreSettings;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BaseHandler;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;
use Manticoresearch\Buddy\Core\Plugin\Pluggable;
use Manticoresearch\Buddy\Core\Process\BaseProcessor;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\SqlQueryParser;
use Manticoresearch\Buddy\Core\Tool\Strings;
use Psr\Container\ContainerInterface;

/**
 * @phpstan-template T of array
 */
class QueryProcessor {
	/** @var ContainerInterface */
	// We set this on initialization (init.php) so we are sure we have it in class
	protected static ContainerInterface $container;

	/** @var bool */
	protected static bool $isInited = false;

	/** @var ManticoreSettings */
	protected static ManticoreSettings $settings;

	/** @var Pluggable */
	protected static Pluggable $pluggable;

	/**
	 * @var SqlQueryParser<T>
	 */
	protected static SqlQueryParser $sqlQueryParser;

	/**
	 * This is the main entry point to start parsing and processing query
	 *
	 * @param Request $request
	 *  The request struct to process
	 * @return BaseHandler
	 *  The BaseHandler to execute to process the final query
	 */
	public static function process(Request $request): BaseHandler {
		if (!static::$isInited) {
			static::init();
		}
		$pluginPrefix = static::detectPluginPrefixFromRequest($request);
		$pluginName = substr($pluginPrefix, strrpos($pluginPrefix, '\\') + 1);
		Buddy::debug("[$request->id] Plugin: $pluginName");
		buddy_metric('plugin_' . Strings::camelcaseToUnderscore($pluginName), 1);
		/** @var BasePayload<T> $payloadClassName */
		$payloadClassName = "{$pluginPrefix}\\Payload";
		$payloadClassName::setParser(static::$sqlQueryParser);
		$pluginPayload = $payloadClassName::fromRequest($request);
		$pluginPayload->setSettings(static::$settings);
		Buddy::debug("[$request->id] $pluginName payload: " . json_encode($pluginPayload));
		$handlerClassName = $pluginPayload->getHandlerClassName();
		/** @var BaseHandler $handler */
		$handler = new $handlerClassName($pluginPayload);
		foreach ($handler->getProps() as $prop) {
			$handler->{'set' . ucfirst($prop)}(static::getObjFromContainer($prop));
		}
		return $handler;
	}

	/**
	 * We should invoke this function before we do anything else with the request.
	 * TODO: think about moving this code into init stage of Settings class itself
	 * @return void
	 * @throws \Exception
	 */
	public static function init(): void {
		// We have tests that going into private properties and change it
		// This is very bad but to not edit it all we will do hack here
		// TODO: Fix it later

		// Get container from Pluggable class to reduce code modifications
		if (!isset(static::$container)) {
			static::$container = Pluggable::getContainer();
		}

		if (!isset(static::$settings)) {
			static::$settings = static::fetchManticoreSettings();
		}

		if (!isset(static::$sqlQueryParser)) {
			/** @phpstan-var SqlQueryParser<T> $instance */
			$instance = SqlQueryParser::getInstance();
			static::$sqlQueryParser = $instance;
		}

		if (!isset(static::$pluggable)) {
			/** @var Pluggable */
			$pluggable = static::getObjFromContainer('pluggable');
			static::$pluggable = $pluggable;
		}
		static::$isInited = true;
	}

	/**
	 * Helper to set settings when we need it before calling to init
	 * @param Settings $settings
	 * @return void
	 */
	public static function setSettings(Settings $settings): void {
		static::$settings = $settings;
	}

	/**
	 * Extract logic to fetch manticore settings and store it in class property
	 * @return ManticoreSettings
	 * @throws GenericError
	 */
	protected static function fetchManticoreSettings(): ManticoreSettings {
		/** @var HTTPClient $manticoreClient */
		$manticoreClient = static::getObjFromContainer('manticoreClient');
		return $manticoreClient->getSettings();
	}

	/**
	 * Return inited settings just for readonly purpose in the external world
	 * @return ManticoreSettings
	 */
	public static function getSettings(): ManticoreSettings {
		return static::$settings;
	}

	/**
	 * Display installed plugins information to the STDOUT
	 * @return void
	 */
	public static function printPluginsInfo(): void {
		echo 'Loaded plugins:' . PHP_EOL;
		foreach (['core', 'local', 'extra'] as $type) {
			$method = 'get' . ucfirst($type) . 'Plugins';
			$plugins = array_map(
				fn($v) => $v['short'],
				QueryProcessor::$method()
			);
			$pluginsLine = implode(', ', $plugins) . PHP_EOL;
			echo "  {$type}: $pluginsLine";
		}
	}

	/**
	 * Get core plugins and exclude local one that does not start with our prefix
	 * @return array<array{full:string,short:string,version:string}>
	 */
	public static function getCorePlugins(): array {
		return static::$pluggable->getCorePlugins();
	}

	/**
	 * Get local plugins by getting diff
	 * @return array<array{full:string,short:string,version:string}>
	 */
	public static function getLocalPlugins(): array {
		return static::$pluggable->getLocalPlugins();
	}

	/**
	 * Get list of external plugins that was installed by using CREATE PLUGIN instruction
	 * @return array<array{full:string,short:string,version:string}>
	 */
	public static function getExtraPlugins(): array {
		return static::$pluggable->getExtraPlugins();
	}

	/**
	 * Get list of disabled plugins
	 * @return array<string,true>
	 */
	public static function getDisabledPlugins(): array {
		return static::$pluggable->getDisabledPlugins();
	}

	/**
	 * Retrieve object from the DI container
	 *
	 * @param string $objName
	 * @return object
	 * @throws GenericError
	 */
	protected static function getObjFromContainer(string $objName): object {
		if (!self::$container->has($objName)) {
			throw new GenericError("Failed to find '$objName' in container");
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
	 * @throws SQLQueryCommandNotSupported|GenericError
	 */
	public static function detectPluginPrefixFromRequest(Request $request): string {
		$list = [
			Pluggable::CORE_NS_PREFIX => static::getCorePlugins(),
			Pluggable::EXTRA_NS_PREFIX => [...static::getExtraPlugins(), ...static::getLocalPlugins()],
		];
		$disabledPlugins = static::getDisabledPlugins();
		foreach ($list as $prefix => $plugins) {
			foreach ($plugins as $plugin) {
				$t = microtime(true);
				$pluginPrefix = $prefix . ucfirst(Strings::camelcaseBySeparator($plugin['short'], '-'));
				/** @var BasePayload<T> $pluginPayloadClass */

				$pluginPayloadClass = "$pluginPrefix\\Payload";
				$pluginPayloadClass::setParser(static::$sqlQueryParser);
				$hasMatch = $pluginPayloadClass::hasMatch($request);
				$duration = (int)((microtime(true) - $t) * 1000);
				$debugMessage = '[' . $duration . 'ms] matching: ' .
					$plugin['short'] . ' - ' .
					($hasMatch ? 'yes' : 'no');
				Buddy::debugv($debugMessage);
				if (!$hasMatch) {
					continue;
				}

				// Do not execute in case the plugin is disabled
				if (isset($disabledPlugins[$plugin['full']])) {
					GenericError::throw("Plugin '{$plugin['short']}' is disabled");
				}

				return $pluginPrefix;
			}
		}

		// No match found? throw the error
		throw new SQLQueryCommandNotSupported("Failed to handle query: $request->payload");
	}

	/**
	 * Run start method of all plugin handlers
	 * We do not need stop cuz swoole manages it for us
	 * @param ?callable $fn
	 * @param array<string> $filter
	 * @return array<array{0:callable,1:integer}>>
	 * @throws GenericError
	 */
	public static function startPlugins(?callable $fn = null, array $filter = []): array {
		/** @var HTTPClient $client ] */
		$client = static::getObjFromContainer('manticoreClient');
		$tickers = [];
		static::$pluggable->iterateProcessors(
			static function (BaseProcessor $processor) use ($fn, $client, &$tickers) {
				if (isset($fn)) {
					$fn($processor->getProcess()->process);
				}
				$processor->setClient($client);
				$tickers += $processor->start();
			}, $filter
		);

		return $tickers;
	}

}

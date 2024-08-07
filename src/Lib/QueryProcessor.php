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
use Manticoresearch\Buddy\Base\Plugin\Sharding\Payload as ShardingPayload;
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

	/** @var array<array{full:string,short:string,version:string}> */
	protected static array $corePlugins = [];

	/** @var array<array{full:string,short:string,version:string}> */
	protected static array $localPlugins = [];

	/** @var array<array{full:string,short:string,version:string}> */
	protected static array $extraPlugins = [];

	/** @var array<string,true> Here we keep disabled plugins map */
	protected static array $disabledPlugins = [];

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

		static::$pluggable = new Pluggable(static::$settings);
		static::$corePlugins = static::$pluggable->fetchCorePlugins();
		static::$localPlugins = static::$pluggable->fetchLocalPlugins();
		static::$extraPlugins = static::$pluggable->fetchExtraPlugins();
		static::$pluggable->registerHooks(static::getHooks());
		static::$isInited = true;
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
		static::iteratePluginProcessors(
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

	/**
	 * Resume plugins after pause
	 * @param array<string> $filter
	 * @return void
	 */
	public static function resumePlugins(array $filter = []): void {
		static::iteratePluginProcessors(
			static function (BaseProcessor $processor) {
				$processor->execute('resume');
			}, $filter
		);
	}

	/**
	 * Run stop method of all plugin handlers
	 * @param array<string> $filter
	 * @return void
	 */
	public static function pausePlugins(array $filter = []): void {
		static::iteratePluginProcessors(
			static function (BaseProcessor $processor) {
				$processor->execute('pause');
			}, $filter
		);
	}

	/**
	 * @param callable $fn
	 * @param array<string> $filter If we pass name we filter only for this plugin
	 * @return void
	 */
	protected static function iteratePluginProcessors(callable $fn, array $filter = []): void {
		$list = [
			[Pluggable::CORE_NS_PREFIX, static::$corePlugins],
			[Pluggable::EXTRA_NS_PREFIX, static::$extraPlugins],
			[Pluggable::EXTRA_NS_PREFIX, static::$localPlugins],
		];
		foreach ($list as [$prefix, $plugins]) {
			foreach ($plugins as $plugin) {
				// If we have filter, we
				if ($filter && !in_array($plugin['full'], $filter)) {
					continue;
				}
				$pluginPrefix = $prefix . ucfirst(Strings::camelcaseBySeparator($plugin['short'], '-'));
				$pluginPayloadClass = "$pluginPrefix\\Payload";
				array_map($fn, $pluginPayloadClass::getProcessors());
			}
		}
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
		return static::$corePlugins;
	}

	/**
	 * Get local plugins by getting diff
	 * @return array<array{full:string,short:string,version:string}>
	 */
	public static function getLocalPlugins(): array {
		return static::$localPlugins;
	}

	/**
	 * Get list of external plugins that was installed by using CREATE PLUGIN instruction
	 * @return array<array{full:string,short:string,version:string}>
	 */
	public static function getExtraPlugins(): array {
		return static::$extraPlugins;
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
			Pluggable::CORE_NS_PREFIX => static::$corePlugins,
			Pluggable::EXTRA_NS_PREFIX => [...static::$extraPlugins, ...static::$localPlugins],
		];
		foreach ($list as $prefix => $plugins) {
			foreach ($plugins as $plugin) {
				$pluginPrefix = $prefix . ucfirst(Strings::camelcaseBySeparator($plugin['short'], '-'));
				/** @var BasePayload<T> $pluginPayloadClass */
				$pluginPayloadClass = "$pluginPrefix\\Payload";
				$pluginPayloadClass::setParser(static::$sqlQueryParser);
				$hasMatch = $pluginPayloadClass::hasMatch($request);
				Buddy::debugv('matching: ' . $plugin['short'] . ' - ' . ($hasMatch ? 'yes' : 'no'));
				if (!$hasMatch) {
					continue;
				}

				// Do not execute in case the plugin is disabled
				if (isset(static::$disabledPlugins[$plugin['full']])) {
					GenericError::throw("Plugin '{$plugin['short']}' is disabled");
				}

				return $pluginPrefix;
			}
		}

		// No match found? throw the error
		throw new SQLQueryCommandNotSupported("Failed to handle query: $request->payload");
	}

	/**
	 * Get hooks to register for Pluggable system
	 * @return array<array{0:string,1:string,2:callable}>
	 */
	protected static function getHooks(): array {
		$hooks = [
			// Happens when we installed the external plugin
			[
				'manticoresoftware/buddy-plugin-plugin',
				'installed',
				static function () {
					static::$extraPlugins = static::$pluggable->fetchExtraPlugins();
				},
			],
			// Happens when we remove the plugin
			[
				'manticoresoftware/buddy-plugin-plugin',
				'deleted',
				static function () {
					static::$extraPlugins = static::$pluggable->fetchExtraPlugins();
				},
			],
			// Happens when we disable the plugin
			[
				'manticoresoftware/buddy-plugin-plugin',
				'disabled',
				static function (string $name) {
					Buddy::debug("Plugin '$name' has been disabled");
					static::$disabledPlugins[$name] = true;
					static::pausePlugins([$name]);
				},
			],
			// Happens when we enable the plugin
			[
				'manticoresoftware/buddy-plugin-plugin',
				'enabled',
				static function (string $name) {
					Buddy::debug("Plugin '$name' has been enabled");
					if (!isset(static::$disabledPlugins[$name])) {
						return;
					}

					unset(static::$disabledPlugins[$name]);
					static::resumePlugins([$name]);
				},
			],
		];

		// If the plugis is not enabled, we just return
		$loadedPlugins = array_column(static::$corePlugins, 'full');
		// Happens when we run create table with shards in options
		if (in_array('manticoresoftware/buddy-plugin-sharding', $loadedPlugins)) {
			$hooks[] = [
				'manticoresoftware/buddy-plugin-sharding',
				'shard',
				static function (array $args) {
					// TODO: remove the reference to the plugin,
					// cuz plugins should be decoupled from the core,
					// but for now for ease of migration we keep it here
					$processor = ShardingPayload::getProcessors()[0];
					$processor->execute('shard', $args);

					$table = $args['table']['name'];
					$processor->addTicker(fn() => $processor->status($table), 1);
				},
			];
		}
		return $hooks;
	}
}

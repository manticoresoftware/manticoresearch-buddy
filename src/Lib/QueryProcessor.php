<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Lib;

use Exception;
use Manticoresearch\Buddy\Base\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Base\Network\EventHandler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings as ManticoreSettings;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BaseHandler;
use Manticoresearch\Buddy\Core\Plugin\Pluggable;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\Strings;
use Psr\Container\ContainerInterface;

class QueryProcessor {
  /** @var string */
	protected const NAMESPACE_PREFIX = 'Manticoresearch\\Buddy\\Plugin\\';

  /** @var ContainerInterface */
  // We set this on initialization (init.php) so we are sure we have it in class
	protected static ContainerInterface $container;

	/** @var bool */
	protected static bool $isInited = false;

	/** @var ManticoreSettings */
	protected static ManticoreSettings $settings;

	/** @var Pluggable */
	protected static Pluggable $pluggable;

	/** @var string[] */
	protected static array $corePlugins = [];

	/** @var string[] */
	protected static array $extraPlugins = [];

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
		$pluginName = str_replace(static::NAMESPACE_PREFIX, '', $pluginPrefix);
		Buddy::debug("[$request->id] Plugin: $pluginName");
		buddy_metric(Strings::camelcaseToUnderscore($pluginName), 1);
		$payloadClassName = "{$pluginPrefix}\\Payload";
		$pluginPayload = $payloadClassName::fromRequest($request);
		$pluginPayload->setSettings(static::$settings);
		Buddy::debug("[$request->id] $pluginName payload: " . json_encode($pluginPayload));
		$handlerClassName = $pluginPayload->getHandlerClassName();
	  /** @var BaseHandler */
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

		static::$pluggable = new Pluggable(
			static::$settings,
			static::getHooks(),
		);
		static::$corePlugins = static::$pluggable->fetchCorePlugins();
		static::$extraPlugins = static::$pluggable->fetchExtraPlugins();

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
	 * Extractd logic to fetch manticore settings and store it in class property
	 * @return ManticoreSettings
	 */
	protected static function fetchManticoreSettings(): ManticoreSettings {
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

			Buddy::debug("using config file = '$value'");
			putenv("SEARCHD_CONFIG={$value}");
		}

		// Gather variables also
		$resp = $manticoreClient->sendRequest('SHOW VARIABLES');
		/** @var array{0:array{columns:array<mixed>,data:array{Setting_name:string,Value:string}}} */
		$data = (array)json_decode($resp->getBody(), true);
		$variables = [];
		foreach ($data[0]['data'] as ['Variable_name' => $key, 'Value' => $value]) {
			$variables[$key] = $value;
		}

		// Finally build the settings
		return ManticoreSettings::fromArray($settings, $variables);
	}

	/**
	 * Return inited settings just for readonly purpose in the external world
	 * @return ManticoreSettings
	 */
	public static function getSettings(): ManticoreSettings {
		return static::$settings;
	}

	/**
	 * Get core plugins and exclude local one that does not start with our prefix
	 * @return array<string>
	 */
	public static function getCorePlugins(): array {
		return array_filter(static::$corePlugins, fn ($v) => !str_starts_with($v, 'manticoresoftware/'));
	}

	/**
	 * Get local plugins by getting diff
	 * @return array<string>
	 */
	public static function getLocalPlugins(): array {
		return array_diff(static::$corePlugins, static::getCorePlugins());
	}

	/**
	 * Get list of external plugins that was installed by using CREATE PLUGIN instruction
	 * @return array<string>
	 */
	public static function getExtraPlugins(): array {
		return static::$extraPlugins;
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
   * @throws SQLQueryCommandNotSupported
   */
	public static function detectPluginPrefixFromRequest(Request $request): string {
		// Try to match plugin to handle and return prefix
		foreach ([...static::$corePlugins, ...static::$extraPlugins] as $plugin) {
			$pluginPrefix = static::NAMESPACE_PREFIX . ucfirst(Strings::camelcaseBySeparator($plugin, '-'));
			$pluginPayloadClass = "$pluginPrefix\\Payload";
			if ($pluginPayloadClass::hasMatch($request)) {
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
		return [
			[
				'manticoresoftware/buddy-plugin-plugin',
				'installed',
				function () {
					static::$extraPlugins = static::$pluggable->fetchExtraPlugins();
					EventHandler::setShouldExit(true);
				},
			],
			[
				'manticoresoftware/buddy-plugin-plugin',
				'deleted',
				function () {
					static::$extraPlugins = static::$pluggable->fetchExtraPlugins();
					EventHandler::setShouldExit(true);
				},
			],
		];
	}
}

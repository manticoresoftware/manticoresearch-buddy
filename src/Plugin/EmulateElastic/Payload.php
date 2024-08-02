<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Exception;
use Manticoresearch\Buddy\Core\Error\InvalidNetworkRequestError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * Prepares payload for Elastic-like queries and determines the appropriate handler for them
 * @extends BasePayload<array>
 */
final class Payload extends BasePayload {

	/**
	 * @var string $requestTarget
	 */
	public static string $requestTarget;

	/**
	 * @var string $requestError
	 */
	public static string $requestError;

	/**
	 * @var array<string,array{properties?:array<mixed>,type?:string,fields?:array<mixed>,
	 * dimension?:int,method?:array<mixed>}> $columnInfo
	 */
	public array $columnInfo;

	/** @var string $table */
	public string $table = '';

	/** @var string $body */
	public string $body = '';

	/** @var string $path */
	public string $path;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Emulates some Elastic queries and generates responses'
		 . ' as if they were made by ES';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$pathParts = explode('/', ltrim($request->path, '/'));
		$self->path = $request->path;
		static::$requestTarget = end($pathParts);
		// As of now, we process all telemetry/metric related requests from Kibana in the same way
		if (str_ends_with(static::$requestTarget, 'telemetry')) {
			static::$requestTarget = 'telemetry';
		} elseif (str_starts_with(static::$requestTarget, 'ui-metric')) {
			static::$requestTarget = 'metric';
		} elseif (str_contains(static::$requestTarget, 'config')) {
			static::$requestTarget = 'config';
		} elseif (str_contains(static::$requestTarget, 'space')) {
			static::$requestTarget = 'space';
		} elseif (str_contains(static::$requestTarget, 'index-pattern')) {
			$self->path = (string)preg_replace('/index-pattern%3A\S+$/', 'index-pattern', $self->path);
			static::$requestTarget = 'index-pattern';
		}
		switch (static::$requestTarget) {
			case '_license':
			case '_nodes':
			case '_xpack':
			case '.kibana':
			case '.kibana_task_manager':
			case '_update_by_query':
			case 'metric':
			case 'config':
			case 'space':
			case 'index-pattern':
			case 'settings':
			case 'telemetry':
			case 'stats':
				break;
			case '_search':
				static::$requestError = $request->error;
				$self->table = $pathParts[0];
				$self->body = $request->payload;
				break;
			case '_mapping':
				/**
				 * @var array{
				 * properties?:array<string,array{properties?:array<mixed>,type?:string,fields?:array<mixed>}>
				 * } $requestBody
				 */
				$requestBody = (array)json_decode($request->payload, true);
				if ($requestBody === [] || !isset($requestBody['properties'])) {
					throw new Exception("Unvalid request body in {$request->path}: $request->payload");
				}
				$self->columnInfo = $requestBody['properties'];
				$self->table = $pathParts[0];
				break;
			default:
				if ($pathParts[0] === '_index_template') {
					// Need this to avoid sending the 404 response for Elasticdump's requests which causes its failure
					$customError = InvalidNetworkRequestError::create('', true);
					$customError->setResponseErrorCode(200);
					throw $customError;
				}
				throw new Exception("Unsupported request type in {$request->path}: " . static::$requestTarget);
		}

		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		return $request->endpointBundle === Endpoint::Elastic;
	}

	/**
	 * @return string
	 */
	public static function getKibanaSearchRequestHandler(): string {
		if (!isset(static::$requestError)) {
			throw new Exception('Error type is not defined');
		}

		if (str_starts_with(static::$requestError, '"_source" property should be')) {
			return 'InvalidSourceKibanaHandler';
		}

		throw new Exception('Cannot find handler for error: ' . static::$requestError);
	}

	/**
	 * @return string
	 */
	public function getHandlerClassName(): string {
		$namespace = __NAMESPACE__ . '\\';
		$handlerName = match (static::$requestTarget) {
			'_license' => 'LicenseHandler',
			'_nodes' => 'NodesInfoKibanaHandler',
			'_xpack' => 'XpackInfoKibanaHandler',
			'.kibana', 'config', 'space', 'index-pattern' => 'SettingsKibanaHandler',
			'_mapping' => 'CreateTableHandler',
			'.kibana_task_manager', '_update_by_query' => 'ManagerSettingsKibanaHandler',
			'settings', 'stats' => 'ClusterKibanaHandler',
			'metric' => 'MetricKibanaHandler',
			'telemetry' => 'TelemetryKibanaHandler',
			'_search' => self::getKibanaSearchRequestHandler(),
			default => throw new Exception('Cannot find handler for request type: ' . static::$requestTarget),
		};

		return $namespace . $handlerName;
	}
}

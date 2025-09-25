<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\OpenSearchDashboards;

use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * Prepares payload for OpenSearch Dashboards queries and determines the appropriate handler for them
 * @extends BasePayload<array>
 */
final class Payload extends BasePayload {

	/** @var string $table */
	public string $table = '';

	/** @var string $body */
	public string $body = '';

	/** @var string $path */
	public string $path;

	/** @var string $requestTarget */
	public static string $requestTarget;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Emulates OpenSearch Dashboards queries and generates responses'
		 . ' as if they were made by OpenSearch';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$pathParts = explode('/', ltrim($request->path, '/'));
		$self->path = $request->path;
		self::detectRequestTarget($pathParts, $self);
		
		// Set body for search requests
		if (static::$requestTarget === '_search' || 
			in_array(static::$requestTarget, ['_doc', '_create', '_update', '_bulk'])) {
			$self->body = $request->payload;
		}

		$self->table = self::extractTableFromPath($pathParts);
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$pathParts = explode('/', ltrim($request->path, '/'));
		$pathParts = array_filter($pathParts);
		$pathParts = array_values($pathParts);

		if (empty($pathParts)) {
			return false;
		}

		$opensearchDashboardsEndpoints = [
			'.opensearch_dashboards',
			'.opensearch_dashboards_task_manager',
			'_search',
			'_cat',
			'_count',
			'_license',
			'_nodes',
			'_xpack',
			'_update_by_query',
			'metric',
			'config',
			'space',
			'index-pattern',
			'settings',
			'telemetry',
			'stats'
		];

		// Check if the first path part matches any OpenSearch Dashboards endpoint
		if (in_array($pathParts[0], $opensearchDashboardsEndpoints)) {
			return true;
		}

		if (isset($pathParts[1]) && in_array($pathParts[1], ['_doc', '_create', '_update', '_search', '_count'])) {
			return true;
		}

		if (isset($pathParts[1]) && $pathParts[1] === '_field_caps') {
			return true;
		}

		return false;
	}

	/**
	 * Detect the request target from path parts
	 * @param array<string> $pathParts
	 * @param Payload $self
	 * @return void
	 */
	protected static function detectRequestTarget(array $pathParts, Payload $self): void {
		if (empty($pathParts)) {
			static::$requestTarget = '';
			return;
		}

		$firstPart = $pathParts[0];
		$secondPart = $pathParts[1] ?? '';

		if (in_array($firstPart, ['.opensearch_dashboards', '.opensearch_dashboards_task_manager'])) {
			static::$requestTarget = $firstPart;
			return;
		}

		if (in_array($firstPart, ['_cat', '_count', '_license', '_nodes', '_xpack', '_search'])) {
			static::$requestTarget = $firstPart;
			return;
		}

		if (in_array($secondPart, ['_doc', '_create', '_update', '_search'])) {
			static::$requestTarget = $secondPart;
			return;
		}

		if ($secondPart === '_field_caps') {
			static::$requestTarget = '_field_caps';
			return;
		}

		// Default to the first part
		static::$requestTarget = $firstPart;
	}

	/**
	 * Extract table name from path parts
	 * @param array<string> $pathParts
	 * @return string
	 */
	protected static function extractTableFromPath(array $pathParts): string {
		if (empty($pathParts)) {
			return '';
		}

		if (in_array($pathParts[0], ['.opensearch_dashboards', '.opensearch_dashboards_task_manager'])) {
			return $pathParts[0];
		}

		if (isset($pathParts[1]) && in_array($pathParts[1], ['_doc', '_create', '_update', '_search'])) {
			return $pathParts[0];
		}

		if (isset($pathParts[1]) && $pathParts[1] === '_field_caps') {
			return $pathParts[0];
		}

		return $pathParts[0];
	}

	/**
	 * Get handler class name for this payload
	 * @return string
	 */
	public function getHandlerClassName(): string {
		return Handler::class;
	}
} 
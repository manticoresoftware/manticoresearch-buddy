<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\OpenSearchDashboards;

/**
 * Trait for loading query mappings in OpenSearch Dashboards plugin
 */
trait QueryMapLoaderTrait {

	/** @var array<string,mixed> */
	private static array $queryMaps = [];

	/**
	 * Load query map for a specific path
	 * @param string $path
	 * @return array<string,mixed>|null
	 */
	protected static function loadQueryMap(string $path): ?array {
		if (empty(self::$queryMaps)) {
			self::loadAllQueryMaps();
		}

		return self::$queryMaps[$path] ?? null;
	}

	/**
	 * Load all query maps
	 * @return void
	 */
	private static function loadAllQueryMaps(): void {
		$queryMapFiles = [
			__DIR__ . '/QueryMap/OpenSearchDashboards.php',
		];

		foreach ($queryMapFiles as $file) {
			if (file_exists($file)) {
				$map = include $file;
				if (is_array($map)) {
					self::$queryMaps = array_merge(self::$queryMaps, $map);
				}
			}
		}
	}

	/**
	 * Get all loaded query maps
	 * @return array<string,mixed>
	 */
	protected static function getAllQueryMaps(): array {
		if (empty(self::$queryMaps)) {
			self::loadAllQueryMaps();
		}

		return self::$queryMaps;
	}
} 
<?php declare(strict_types=1);

/*
  Copyright (c) 2024-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use RuntimeException;

trait KibanaVersionTrait {

	const DEFAULT_KIBANA_VERSION = '7.6.0';

	/**
	 * @param HTTPClient $manticoreClient
	 * @return string
	 */
	protected static function getKibanaVersion(HTTPClient $manticoreClient): string {
		/** @var Settings $settings */
		$settings = $manticoreClient->getSettings();
		return $settings->searchdKibanaVersionString ?? self::DEFAULT_KIBANA_VERSION;
	}

	/**
	 *
	 * @param array<mixed> $entityList
	 * @return array<mixed>
	 * @throws RuntimeException
	 */
	protected static function getVersionedEntity(array $entityList, HTTPClient $manticoreClient): array {
		if (!$entityList || !array_is_list($entityList)) {
			return $entityList;
		}
		$kibanaVersion = self::getKibanaVersion($manticoreClient);
		foreach ($entityList as $entityItem) {
			// Extra checks to make sure it's a valid versioned entity
			if (sizeof( array_keys($entityItem) ) !== 1) {
				return $entityList;
			}
			$entityVersion = array_key_first($entityItem);
			if (!preg_match('/^\d{1,2}\.\d{1,2}\.\d{1,2}$/', $entityVersion)) {
				return $entityList;
			}

			if ($entityVersion === $kibanaVersion) {
				return $entityItem[$entityVersion];
			}
		}
		
		throw new \RuntimeException('Unknown Kibana version requested');
	}

	/**
	 * @param HTTPClient $manticoreClient
	 * @return bool
	 */
	protected static function isCommonKibana(HTTPClient $manticoreClient): bool {
		/** @var Settings $settings */
		$settings = $manticoreClient->getSettings();
		return (isset($settings->searchdKibanaVersionString)
			&& version_compare($settings->searchdKibanaVersionString, self::DEFAULT_KIBANA_VERSION, '>='));
	}
	
}

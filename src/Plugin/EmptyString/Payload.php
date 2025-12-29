<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\EmptyString;

use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	public string $path;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Handles empty queries,'
			. ' which can occur when trimming comments or dealing with specific SQL'
			. ' protocol instructions in comments that are not supported';
	}

  /**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		// We just need to do something, but actually its' just for PHPstan
		$self->path = $request->path;
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$payload = strtolower($request->payload);
		/*if ($request->payload === ''
			&& $request->endpointBundle !== Endpoint::Metrics
			&& $request->endpointBundle !== Endpoint::Bulk
			&& $request->endpointBundle !== Endpoint::Elastic) {
			return true;
		}*/
		if ($request->command === 'set') {
			$setPatterns = [
				'sql_quote_show_create',
				'@saved_cs_client',
				'@@session',
				'character_set_client',
				'session character_set_results',
				'session transaction',
				'sql_select_limit',
			];
			foreach ($setPatterns as $pattern) {
				if (stripos($payload, $pattern) === 4) {
					return true;
				}
			}
		}

		return match ($request->command) {
			'create' => stripos($payload, 'create database') === 0,
			default => false,
		};
	}
}

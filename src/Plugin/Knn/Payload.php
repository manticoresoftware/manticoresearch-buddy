<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Knn;

use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 */
final class Payload extends BasePayload
{
	public ?string $field = null;

	public ?string $k = null;
	public ?string $docId = null;

	/** @var array<string> */
	public array $select = [];

	public ?string $table = null;

	public Endpoint $endpointBundle;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enables KNN by document id';
	}

	/**
	 * @param  Request  $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();

		$self->endpointBundle = $request->endpointBundle;
		// If we need process this query as http request
		if ($self->endpointBundle === Endpoint::Search) {
			$self->select = ['id', 'knn_dist()'];

			$payload = json_decode($request->payload, true);
			if (is_array($payload)) {
				if (isset($payload['_source'])) {
					$self->select = $payload['_source'];
				}
				$self->table = $payload['index'];
				$self->field = $payload['knn']['field'];
				$self->k = (string)$payload['knn']['k'];
				$self->docId = (string)$payload['knn']['doc_id'];
			}
		} else {
			$matches = $self::getMatches($request);

			$self->select = array_map(
				function ($row) {
					return trim($row);
				}, explode(',', $matches[1] ?? '')
			);
			$self->table = $matches[2] ?? null;
			$self->field = $matches[3] ?? null;
			$self->k = $matches[4] ?? null;
			$self->docId = $matches[5] ?? null;
		}

		return $self;
	}

	/**
	 * @param  Request  $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		if ($request->endpointBundle === Endpoint::Search) {
			$payload = json_decode($request->payload, true);
			if (is_array($payload) && isset($payload['knn']['doc_id'])) {
				return true;
			}
		}

		if (stripos($request->payload, 'knn') !== false && self::getMatches($request)) {
			return true;
		}

		return false;
	}

	/**
	 * @param  Request  $request
	 * @return array<string>|bool
	 */
	private static function getMatches(Request $request): array|bool {
		$pattern = '/^select\s+(.*)from\s+`*([a-z0-9_-]+)`*\s+'.
			'.*?knn\s+\(\s*(.*)?\s*,\s*([0-9]+)\s*,\s*([0-9]+)\s*\)/usi';
		if (!preg_match($pattern, $request->payload, $matches)) {
			return false;
		}

		return $matches;
	}
}

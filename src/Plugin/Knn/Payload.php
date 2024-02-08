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

	/** @var string|null table */
	public ?string $table = null;

	/** @var array<string> */
	public array $condition = [];

	public Endpoint $endpointBundle;

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$self->endpointBundle = $request->endpointBundle;

		// If we need process this query as http request
		if ($self->endpointBundle === Endpoint::Search) {
			self::parseHttpRequest($self, $request);
		} else {
			$self::parseSqlRequest($self);
		}

		return $self;
	}

	/**
	 * @param Payload $self
	 * @param Request $request
	 * @return void
	 */
	private static function parseHttpRequest(self $self, Request $request): void {
		$self->select = ['id', 'knn_dist()'];

		$payload = json_decode($request->payload, true);
		if (!is_array($payload)) {
			return;
		}

		if (isset($payload['_source'])) {
			$self->select = $payload['_source'];
		}
		if (isset($payload['query'])) {
			$self->condition = $payload['query'];
		}
		$self->table = $payload['index'];
		$self->field = $payload['knn']['field'];
		$self->k = (string)$payload['knn']['k'];
		$self->docId = (string)$payload['knn']['doc_id'];
	}

	/**
	 * @param Payload $self
	 * @return void
	 */
	private static function parseSqlRequest(self $self): void {


		$payload = static::$sqlQueryParser::getParsedPayload();
		$self->table = $payload['FROM'][0]['table'] ?? null;

		if (!isset($payload['WHERE'])) {
			return;
		}

		foreach ($payload['WHERE'] as $condition) {
			if ($condition['base_expr'] !== 'knn') {
				continue;
			}

			$self->field = (string)$condition['sub_tree'][0]['base_expr'];
			$self->k = (string)$condition['sub_tree'][1]['base_expr'];
			$self->docId = (string)$condition['sub_tree'][2]['base_expr'];
		}
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		if ($request->endpointBundle === Endpoint::Search) {
			$payload = json_decode($request->payload, true);
			if (is_array($payload) && isset($payload['knn']['doc_id'])) {
				return true;
			}
		}

		$payload = static::$sqlQueryParser::parse($request->payload);
		if (isset($payload['WHERE'])) {
			foreach ($payload['WHERE'] as $expression) {
				if ($expression['expr_type'] === 'function'
				&& $expression['base_expr'] === 'knn'
					&& isset($expression['sub_tree'][2])
					&& is_numeric($expression['sub_tree'][2]['base_expr'])
				) {
					return true;
				}
			}
		}

		return false;
	}
}

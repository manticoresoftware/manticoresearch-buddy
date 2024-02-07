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
use Manticoresearch\Buddy\Core\Tool\SqlQueryParser;

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

	/** @var array<string> */
	public array $condition = [];

	public Endpoint $endpointBundle;

	/**
	 * @param Request $request
	 * @param SqlQueryParser|null $sqlQueryParser
	 * @return static
	 */
	public static function fromRequest(Request $request, ?SqlQueryParser $sqlQueryParser = null): static
	{
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
				if (isset($payload['query'])) {
					$self->condition = $payload['query'];
				}
				$self->table = $payload['index'];
				$self->field = $payload['knn']['field'];
				$self->k = (string)$payload['knn']['k'];
				$self->docId = (string)$payload['knn']['doc_id'];
			}
		} else {
			$payload = static::$sqlQueryParser::getParsedPayload();
			$self->table = $payload['FROM'][0]['table'];

			foreach ($payload['WHERE'] as $condition) {
				if ($condition['base_expr'] === 'knn') {
					$self->field = (string)$condition['sub_tree'][0]['base_expr'];
					$self->k = (string)$condition['sub_tree'][1]['base_expr'];
					$self->docId = (string)$condition['sub_tree'][2]['base_expr'];
				}
			}
		}

		return $self;
	}

	/**
	 * @param Request $request
	 * @param SqlQueryParser|null $sqlQueryParser
	 * @return bool
	 */
	public static function hasMatch(Request $request, ?SqlQueryParser $sqlQueryParser = null): bool
	{
		if ($request->endpointBundle === Endpoint::Search) {
			$payload = json_decode($request->payload, true);
			if (is_array($payload) && isset($payload['knn']['doc_id'])) {
				return true;
			}
		}

		$payload = static::$sqlQueryParser::parse($request->payload);
		if (isset($payload['WHERE'])) {
			foreach ($payload['WHERE'] as $expression) {
				if ($expression['expr_type'] === 'function' && $expression['base_expr'] === 'knn' &&
					isset($expression['sub_tree'][2]) && is_numeric($expression['sub_tree'][2]['base_expr'])
				) {
					return true;
				}
			}
		}

		return false;
	}
}

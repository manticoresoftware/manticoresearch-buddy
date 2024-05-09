<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Knn;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload
{
	public ?string $field = null;

	public ?string $k = null;
	public ?string $docId = null;

	/** @var array<string> $select */
	public array $select = [];

	/** @var ?string $table */
	public ?string $table = null;

	/** @var array<string> $condition */
	public array $condition = [];

	public Endpoint $endpointBundle;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enables KNN by document id';
	}

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
	 * @param Payload $payload
	 * @param Request $request
	 * @return void
	 */
	private static function parseHttpRequest(self $payload, Request $request): void {
		$payload->select = [];

		$parsedPayload = json_decode($request->payload, true);
		if (!is_array($parsedPayload)) {
			return;
		}

		if (isset($parsedPayload['_source'])) {
			$payload->select = $parsedPayload['_source'];
		}
		if (isset($parsedPayload['knn']['filter'])) {
			$payload->condition = $parsedPayload['knn']['filter'];
		}
		$payload->table = $parsedPayload['index'];
		$payload->field = $parsedPayload['knn']['field'];
		$payload->k = (string)$parsedPayload['knn']['k'];
		$payload->docId = (string)$parsedPayload['knn']['doc_id'];
	}

	/**
	 * @param Payload $payload
	 * @return void
	 */
	private static function parseSqlRequest(self $payload): void {
		$parsedPayload = static::$sqlQueryParser::getParsedPayload();
		$payload->table = $parsedPayload['FROM'][0]['table'] ?? null;

		if (!isset($parsedPayload['WHERE'])) {
			return;
		}

		foreach ($parsedPayload['WHERE'] as $condition) {
			if ($condition['base_expr'] !== 'knn') {
				continue;
			}

			$payload->field = (string)$condition['sub_tree'][0]['base_expr'];
			$payload->k = (string)$condition['sub_tree'][1]['base_expr'];
			$payload->docId = (string)$condition['sub_tree'][2]['base_expr'];
		}
	}

	/**
	 * @param Request $request
	 * @return bool
	 * @throws GenericError
	 */
	public static function hasMatch(Request $request): bool {
		if ($request->endpointBundle === Endpoint::Search) {
			$payload = json_decode($request->payload, true);
			if (is_array($payload) && isset($payload['knn']['doc_id'])) {
				return true;
			}
		}

		$payload = self::getParsedPayload($request);

		if (!$payload) {
			return false;
		}
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

	/**
	 * Small preprocessing. Allow us to not call hard $sqlQueryParser::parse method
	 *
	 * @param Request $request
	 * @return array{WHERE?: array<int, array{expr_type:string, base_expr:string,
	 *   sub_tree: array<int,array{base_expr:string, expr_type:string}>}>}|null
	 * @throws GenericError
	 */
	private static function getParsedPayload(Request $request): ?array {
		return static::$sqlQueryParser::parse(
			$request->payload,
			fn($request) => (str_contains($request->error, "P01: syntax error, unexpected integer, expecting '(' near")
				&& stripos($request->payload, 'knn') !== false
				&& preg_match('/\(?\s?knn\s?\(/usi', $request->payload)),
			$request
		);
	}

}

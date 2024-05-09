<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Replace;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	public string $path;

	public string $table;
	/** @var array<string, bool|float|int|string> */
	public array $set;
	public int $id;
	public string $type;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enables partial replaces';
	}

	/**
	 * @param Request $request
	 * @return static
	 * @throws ManticoreSearchClientError
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$self->path = $request->path;
		$self->type = $request->format->value;

		if ($request->format->value === RequestFormat::SQL->value) {

			/** @var array{REPLACE:array<int, array{table?:string, expr_type: string, base_expr: string,
			 *   no_quotes: array{parts: array<int, string>}}>,
			 *   SET:array<int, array{expr_type: string, sub_tree: array<int, array{base_expr: string}>}>,
			 *   WHERE:array<int, array{base_expr: string}>} $payload
			 */
			$payload = static::$sqlQueryParser::getParsedPayload();

			$self->table = self::parseTable($payload['REPLACE']);
			$self->set = self::parseSet($payload['SET']);
			$self->id = (int)$payload['WHERE'][2]['base_expr'];
		} else {
			$pathChunks = explode('/', $request->path);
			$payload = json_decode($request->payload, true);


			$self->table = $pathChunks[0] ?? '';
			$self->id = (int)$pathChunks[2];

			if (is_array($payload)) {
				$self->set = $payload['doc'] ?? [];
			}
		}
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {

		if ($request->format->value === RequestFormat::SQL->value) {
			$payload = static::$sqlQueryParser::parse(
				$request->payload,
				fn($request) => (
					str_contains($request->error, "P01: syntax error, unexpected SET, expecting VALUES near '")
					&& stripos($request->payload, 'replace') !== false
					&& stripos($request->payload, 'set') !== false
					&& stripos($request->payload, 'where') !== false),
				$request
			);

			if (isset($payload['REPLACE'])
				&& isset($payload['SET'])
				&& isset($payload['WHERE'][0]['base_expr']) && $payload['WHERE'][0]['base_expr'] === 'id'
				&& isset($payload['WHERE'][1]['base_expr']) && $payload['WHERE'][1]['base_expr'] === '='
				&& isset($payload['WHERE'][2]['base_expr']) && is_numeric($payload['WHERE'][2]['base_expr'])
				&& sizeof($payload['WHERE']) === 3) {
				return true;
			}
			return false;
		}

		return str_contains($request->path, '/_update/');
	}

	/**
	 * @param array<int, array{table?:string, expr_type: string, base_expr: string,
	 *   no_quotes: array{parts: array<int, string>}}> $tableStatement
	 * @return string
	 * @throws ManticoreSearchClientError
	 */
	public static function parseTable(array $tableStatement): string {
		foreach ($tableStatement as $item) {
			if (isset($item['table']) && $item['expr_type'] === 'table') {
				return $item['no_quotes']['parts'][0];
			}
		}
		throw ManticoreSearchClientError::create('Can\'t parse table name');
	}

	/**
	 * @param array<int, array{expr_type: string, sub_tree: array<int, array{base_expr: string}>}> $setStatement
	 * @return array <string, string>
	 */
	public static function parseSet(array $setStatement): array {
		$result = [];

		foreach ($setStatement as $singleStatement) {
			if (!isset($singleStatement['sub_tree'][0]['base_expr'])
				|| !isset($singleStatement['sub_tree'][1]['base_expr'])
				|| !isset($singleStatement['sub_tree'][2]['base_expr'])) {
				continue;
			}

			$result[$singleStatement['sub_tree'][0]['base_expr']] = $singleStatement['sub_tree'][2]['base_expr'];
		}

		return $result;
	}
}

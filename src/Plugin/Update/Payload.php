<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Update;

use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @extends BasePayload<array>
 */
final class Payload extends BasePayload
{
	public string $path;

	public string $table;
	public string $setExpr;
	public string $whereExpr;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Handles UPDATE statements sent by MySQL tools making possible for them to update full-text fields';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$self->path = $request->path;
		/** @var array{
		 *   UPDATE: array<int, array{no_quotes: array{parts: array<int, string>}}>,
		 *   SET: array<int, array{base_expr: string}>,
		 *   WHERE: array<int, array{base_expr: string}>
		 *   } $payload
		 */
		$payload = Payload::$sqlQueryParser::getParsedPayload();

		$self->table = $payload['UPDATE'][0]['no_quotes']['parts'][1];
		$self->setExpr = $payload['SET'][0]['base_expr'];
		$self->whereExpr = $payload['WHERE'][0]['base_expr'] . '=' . $payload['WHERE'][2]['base_expr'];

		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		// As of now, we use the plugin to handle only incorrect UPDATEs that come from MySQL tools
		if (!isset($request->mySQLTool)) {
			return false;
		}

		$payload = static::$sqlQueryParser::parse(
			$request->payload,
			fn($request) => preg_match('/attribute \S+ not found/', $request->error),
			$request
		);

		return (isset(
			$payload['UPDATE'][0]['no_quotes']['parts'][1], $payload['SET'][0]['base_expr'],
			$payload['WHERE'][0]['base_expr'], $payload['WHERE'][1]['base_expr'], $payload['WHERE'][2]['base_expr']
		)
		&& $payload['WHERE'][0]['base_expr'] === 'id'
		&& $payload['WHERE'][1]['base_expr'] === '='
		&& is_numeric($payload['WHERE'][2]['base_expr'])
		&& sizeof($payload['WHERE']) === 3);
	}
}

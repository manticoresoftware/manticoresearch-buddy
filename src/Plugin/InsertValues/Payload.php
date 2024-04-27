<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\InsertValues;

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	/** @var string $table */
	public string $table;

	/** @var array<string|int> $values */
	public array $values;

	/** @var string $path */
	public string $path;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Manages the restoration of NULLs and MVA fields with mysqldump';
	}

  /**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$self->path = $request->path;
		preg_match('/^insert\s+into\s+`?([^ ]+?)`?(?:\s+\(([^)]+)\))?\s+values/ius', $request->payload, $matches);
		$table = $matches[1] ?? null;
		if (!$table) {
			throw QueryParseError::create('Failed to parse table from the query');
		}
		$self->table = $table;

		// It's time to parse values
		$pattern = '/values\s*\((.*)\)/ius';
		preg_match($pattern, $request->payload, $matches);

		if (!isset($matches[1])) {
			throw QueryParseError::create('Failed to parse values from the query');
		}

		$values = $matches[1];
		$pattern = "/'(?:[^'\\\\]*(?:\\\\.[^'\\\\]*)*)'|\\d+(?:\\.\\d+)?|NULL|\\{(?:[^{}]|\\{[^{}]*\\})*\\}/";
		preg_match_all($pattern, $values, $matches);
		$self->values = array_map(trim(...), $matches[0] ?? []);
		$self->path = $request->path;
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$isInsertQuery = stripos($request->payload, 'insert into') === 0;
		if (!$isInsertQuery) {
			return false;
		}

		// ERROR 1064 (42000): row 1, column 3: non-MVA value specified for a MVA column
		$isMVAError = str_contains($request->error, 'non-MVA value specified for a MVA column');
		if ($isMVAError) {
			return true;
		}
		// ERROR 1064 (42000) at line 77: P01: syntax error, unexpected NULL near
		$isNullError = str_contains($request->error, 'unexpected NULL near');
		if ($isNullError) {
			return true;
		}

		$isKnnError = str_contains($request->error, 'KNN error: data has 0 values');
		if ($isKnnError) {
			return true;
		}

		return false;
	}
}

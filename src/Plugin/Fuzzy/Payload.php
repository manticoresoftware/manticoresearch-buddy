<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Fuzzy;

use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * Request for Backup command that has parsed parameters from SQL
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	/** @var string */
	public string $table;

	/** @var int */
	public int $distance;

	/** @var string */
	public string $query;

	/** @var string */
	public string $template;

	public function __construct() {
	}

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Fuzzy search plugin. It helps to find the best match for a given query.';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$query = $request->payload;
		preg_match('/FROM\s+(\w+)\s+WHERE\s+MATCH\s*\(\'(.*?)\'\)/ius', $query, $matches);
		$tableName = $matches[1] ?? '';
		$searchValue = $matches[2] ?? '';

		preg_match('/distance\s*=\s*(\d+)/ius', $query, $matches);
		$distanceValue = (int)($matches[1] ?? 2);

		$self = new static();
		$self->query = $searchValue;
		$self->table = $tableName;
		$self->distance = $distanceValue;
		$self->template = (string)preg_replace(
			[
				'/MATCH\(\'(.*)\'\)/ius',
				'/(fuzzy|distance)\s*=\s*\d+[,\s\0]*/ius',
				'/option,/ius',
			],
			[
				'MATCH(\'%s\')',
				'',
				'option ',
			],
			$query
		);
		$self->template = trim(str_replace("\0", '', $self->template));
		if (str_ends_with($self->template, 'option')) {
			$self->template = substr($self->template, 0, -6);
		}
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		return stripos($request->payload, 'select') === 0
			&& stripos($request->payload, 'match') !== false
			&& stripos($request->payload, 'option') !== false
			&& stripos($request->payload, 'fuzzy') !== false
			&& stripos($request->error, 'unknown option') !== false
		;
	}
}

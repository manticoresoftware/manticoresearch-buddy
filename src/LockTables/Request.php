<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\LockTables;

use Manticoresearch\Buddy\Base\CommandRequestBase;
use Manticoresearch\Buddy\Network\Request as NetRequest;

final class Request extends CommandRequestBase {
	public string $endpoint;

	/**
	 * Initialize request with tables to lock
	 * @param array<array{name:string,alias:string|null,lock:string}> $tables
	 * @return void
	 */
	public function __construct(public array $tables = []) {
	}

  /**
	 * @param NetRequest $request
	 * @return self
	 */
	public static function fromNetworkRequest(NetRequest $request): Request {
		// Cut lock tables prefix
		$query = trim(substr($request->payload, 11));
		preg_match_all('/([a-z_]+)(\sAS\s([a-z_]+))?\s([a-z_]+)/i', $query, $matches, PREG_SET_ORDER);

		// We parse lock type and alias but actually do not use now
		$tables = [];
		foreach ($matches as $match) {
			$tables[] = [
				'name' => $match[1],
				'alias' => $match[3] ?? null,
				'lock' => $match[4],
			];
		}

		$self = new self($tables);
		$self->endpoint = $request->endpointBundle->value;
		return $self;
	}
}

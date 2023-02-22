<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\UnlockTables;

use Manticoresearch\Buddy\Base\CommandRequestBase;
use Manticoresearch\Buddy\Network\Request as NetRequest;

final class Request extends CommandRequestBase {
	public string $endpoint;

	/**
	 * Initialize request with tables to lock
	 * @param array<string> $tables
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
		$query = trim(substr($request->payload, 13));
		preg_match_all('/(ALL|([a-z_]+)(\s*,\s*[a-z_]+)*)/i', $query, $matches, PREG_SET_ORDER);

		// We parse lock type and alias but actually do not use now
		if ($matches && $matches[1][0] !== 'ALL') {
			$tables = explode(',', $matches[1][0]);
			$tables = array_map('trim', $tables);
		} else {
			$tables = [];
		}

		$self = new self($tables);
		$self->endpoint = $request->path;
		return $self;
	}
}

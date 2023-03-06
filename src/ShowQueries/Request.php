<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\ShowQueries;

use Manticoresearch\Buddy\Base\CommandRequestBase;
use Manticoresearch\Buddy\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Network\Request as NetRequest;

/**
 * Request for Backup command that has parsed parameters from SQL
 */
final class Request extends CommandRequestBase {
	public string $query;
	public string $path;
	public bool $hasCliEndpoint;

	/**
	 * @param NetRequest $request
	 * @return self
	 */
	public static function fromNetworkRequest(NetRequest $request): Request {
		if (trim(strtolower($request->payload)) !== 'show queries') {
			throw new SQLQueryCommandNotSupported("Invalid query passed: $request->payload");
		}
		$self = new self();
		$self->query = 'SELECT * FROM @@system.sessions';
		[$self->path, $self->hasCliEndpoint] = self::getEndpointInfo($request);
		return $self;
	}
}

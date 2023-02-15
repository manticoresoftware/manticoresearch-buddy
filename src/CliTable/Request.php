<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\CliTable;

use Manticoresearch\Buddy\Base\CommandRequestBase;
use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Network\Request as NetRequest;

/**
 * Request for CliTable command
 */
final class Request extends CommandRequestBase {
	public string $query;
	public ManticoreEndpoint $endpoint;

	/**
	 * @param NetRequest $request
	 * @return self
	 */
	public static function fromNetworkRequest(NetRequest $request): Request {
		$self = new self();
		$self->query = $request->payload;
		$self->endpoint = ManticoreEndpoint::Sql;
		return $self;
	}
}
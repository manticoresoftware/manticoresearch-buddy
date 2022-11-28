<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Interface\CommandRequestInterface;
use Manticoresearch\Buddy\Network\Request;

/**
 * Request for Backup command that has parsed parameters from SQL
 */
class ShowQueriesRequest implements CommandRequestInterface {
	public string $query;
	public ManticoreEndpoint $endpoint;

	public function __construct() {
	}

	/**
	 * @param Request $request
	 * @return self
	 */
	public static function fromNetworkRequest(Request $request): ShowQueriesRequest {
		$self = new self();
		$self->query = 'SELECT * FROM @@system.sessions';
		$self->endpoint = $request->endpoint;
		return $self;
	}
}

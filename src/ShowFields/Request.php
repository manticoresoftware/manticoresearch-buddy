<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\ShowFields;

use Manticoresearch\Buddy\Base\CommandRequestBase;
use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Exception\SQLQueryParsingError;
use Manticoresearch\Buddy\Network\Request as NetRequest;

final class Request extends CommandRequestBase {
	public ManticoreEndpoint $endpoint;

	public function __construct(public string $table) {
	}

  /**
	 * @param NetRequest $request
	 * @return self
	 */
	public static function fromNetworkRequest(NetRequest $request): Request {
		$pattern = '#show fields from'
			. '\s+`?(?P<table>([a-z][a-z0-9\_]*))`?'
			. '$#ius';
		if (!preg_match($pattern, $request->payload, $m)) {
			throw SQLQueryParsingError::create('You have an error in your query. Please, double-check it.');
		}

		$self = new self($m['table']);
		$self->endpoint = $request->endpoint;
		return $self;
	}
}

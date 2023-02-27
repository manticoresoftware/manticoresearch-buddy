<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\EmulateElastic;

use Manticoresearch\Buddy\Base\CommandRequestBase;
use Manticoresearch\Buddy\Network\Request as NetRequest;

/**
 * Request for Backup command that has parsed parameters from SQL
 */
final class Request extends CommandRequestBase {

	/**
	 * @param NetRequest $request
	 * @return self
	 */
	public static function fromNetworkRequest(NetRequest $request): Request {
		unset($request);
		return new self();
	}
}

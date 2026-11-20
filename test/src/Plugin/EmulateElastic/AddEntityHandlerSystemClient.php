<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if not, see <https://www.gnu.org/licenses/>.
*/

namespace Manticoresearch\BuddyTest\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\ManticoreSearch\SystemClient;

final class AddEntityHandlerSystemClient extends SystemClient {
	public function __construct(private AddEntityHandlerRecords $records) {
		parent::__construct();
	}

	public function sendRequest(
		string $request,
		?string $path = null,
		bool $disableAgentHeader = false,
		string $requestMethod = 'POST',
	): Response {
		unset($path, $disableAgentHeader, $requestMethod);
		return $this->records->record($request);
	}
}

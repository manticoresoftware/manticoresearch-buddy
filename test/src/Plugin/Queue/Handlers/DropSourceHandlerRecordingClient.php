<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Plugin\Queue\Handlers;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;

final class DropSourceHandlerRecordingClient extends Client {
	public function __construct(private DropSourceHandlerClientRecords $records) {
		parent::__construct();
	}

	public function sendRequest(
		string $request,
		?string $path = null,
		bool $disableAgentHeader = false,
		string $requestMethod = 'POST',
	): Response {
		unset($path, $disableAgentHeader, $requestMethod);

		$this->records->requests[] = ['query' => $request, 'user' => $this->delegatedUser];

		if ($request === 'SHOW TABLES FROM system') {
			return Response::fromBody(
				'[{"error":"","warning":"","total":1,"data":[' .
				'{"Table":"system.materialized_view_orders","Type":"rt"}]}]'
			);
		}

		return Response::fromBody('[{"error":"","warning":"","total":0,"data":[]}]');
	}

	public function getDelegatedUser(): ?string {
		return $this->delegatedUser;
	}
}

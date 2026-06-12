<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Plugin\Queue\Handlers;

use Manticoresearch\Buddy\Core\ManticoreSearch\Response;

final class DropSourceHandlerClientRecords {
	/** @var list<array{query:string,user:?string}> */
	public array $requests = [];

	/**
	 * Record the request and return the canned response, so every
	 * recording client (user or system) replays the same fixture.
	 *
	 * @param string $query
	 * @param ?string $user
	 * @return Response
	 */
	public function record(string $query, ?string $user): Response {
		$this->requests[] = ['query' => $query, 'user' => $user];

		if ($query === 'SHOW TABLES FROM system') {
			return Response::fromBody(
				'[{"error":"","warning":"","total":1,"data":[' .
				'{"Table":"system.materialized_view_orders","Type":"rt"}]}]'
			);
		}

		return Response::fromBody('[{"error":"","warning":"","total":0,"data":[]}]');
	}
}

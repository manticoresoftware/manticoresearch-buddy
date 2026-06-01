<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source\DropSourceHandler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use PHPUnit\Framework\TestCase;

final class DropSourceHandlerTest extends TestCase {
	public function testSuspendSourceViewsUsesSystemBuddyClient(): void {
		$records = new DropSourceHandlerClientRecords();
		$client = new DropSourceHandlerRecordingClient($records);
		$client->setDelegatedUser('alice');

		$reflection = new ReflectionClass(DropSourceHandler::class);
		$method = $reflection->getMethod('suspendSourceViews');
		$method->setAccessible(true);
		$method->invoke(null, 'orders_0', $client);

		$this->assertSame(
			[
				['query' => 'SHOW TABLES FROM system', 'user' => 'system.buddy'],
				[
					'query' => 'UPDATE system.materialized_view_orders ' .
						'SET suspended=1 WHERE match(\'@source_name "orders_0"\')',
					'user' => 'system.buddy',
				],
			],
			$records->requests
		);
		$this->assertSame('alice', $client->getDelegatedUser());
	}
}

final class DropSourceHandlerClientRecords {
	/** @var list<array{query:string,user:?string}> */
	public array $requests = [];
}

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

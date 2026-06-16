<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source\DropSourceHandler;
use Manticoresearch\BuddyTest\Plugin\Queue\Handlers\DropSourceHandlerClientRecords;
use Manticoresearch\BuddyTest\Plugin\Queue\Handlers\DropSourceHandlerRecordingClient;
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

<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Plugin\PluginsAuthPermissions;

use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase {
	public function testMatchesOnlyFailedGrantOrRevokeResourceQueries(): void {
		$this->assertTrue(Payload::hasMatch($this->createRequest('GRANT SELECT ON source/orders TO user', 'error')));
		$this->assertTrue(Payload::hasMatch($this->createRequest('REVOKE SELECT ON mva/sales FROM user', 'error')));
		$this->assertTrue(
			Payload::hasMatch($this->createRequest('REVOKE SELECT ON materialized view/sales FROM user', 'error'))
		);
		$this->assertTrue(Payload::hasMatch($this->createRequest('GRANT SELECT ON chat model/gpt TO user', 'error')));
		$this->assertFalse(Payload::hasMatch($this->createRequest('GRANT SELECT ON source/orders TO user', '')));
		$this->assertFalse(Payload::hasMatch($this->createRequest('GRANT SELECT ON orders TO user', 'error')));
	}

	public function testMorphsSourceGrantToSystemResourceTable(): void {
		$payload = Payload::fromRequest($this->createRequest('GRANT SELECT ON source/orders TO user', 'error'));

		$this->assertSame('source', $payload->resource);
		$this->assertSame('orders', $payload->resourceName);
		$this->assertSame("GRANT SELECT ON 'system.source_orders' TO user", $payload->morphedQuery);
	}

	public function testMorphsChatModelRevokeToSystemResourceTable(): void {
		$payload = Payload::fromRequest($this->createRequest('REVOKE SELECT ON chat_model/gpt FROM user', 'error'));

		$this->assertSame('chat_model', $payload->resource);
		$this->assertSame('gpt', $payload->resourceName);
		$this->assertSame("REVOKE SELECT ON 'system.chat_model_gpt' FROM user", $payload->morphedQuery);
	}

	public function testChatModelResourceMorphsToSystemResourceTable(): void {
		$payload = Payload::fromRequest($this->createRequest('REVOKE SELECT ON chat model/gpt FROM user', 'error'));

		$this->assertSame('chat_model', $payload->resource);
		$this->assertSame('gpt', $payload->resourceName);
		$this->assertSame("REVOKE SELECT ON 'system.chat_model_gpt' FROM user", $payload->morphedQuery);
	}

	public function testMvaAliasMorphsToMaterializedViewResourceTable(): void {
		$payload = Payload::fromRequest($this->createRequest('GRANT SELECT ON mva/sales TO user', 'error'));

		$this->assertSame('materialized_view', $payload->resource);
		$this->assertSame('sales', $payload->resourceName);
		$this->assertSame("GRANT SELECT ON 'system.materialized_view_sales' TO user", $payload->morphedQuery);
	}

	public function testMaterializedViewResourceMorphsToMaterializedViewResourceTable(): void {
		$payload = Payload::fromRequest(
			$this->createRequest('GRANT SELECT ON materialized view/sales TO user', 'error')
		);

		$this->assertSame('materialized_view', $payload->resource);
		$this->assertSame('sales', $payload->resourceName);
		$this->assertSame("GRANT SELECT ON 'system.materialized_view_sales' TO user", $payload->morphedQuery);
	}

	public function testMvaWildcardMorphsToMaterializedViewResourceWildcard(): void {
		$payload = Payload::fromRequest($this->createRequest('GRANT SELECT ON mva/* TO user', 'error'));

		$this->assertSame('materialized_view', $payload->resource);
		$this->assertSame('*', $payload->resourceName);
		$this->assertSame("GRANT SELECT ON 'system.materialized_view_*' TO user", $payload->morphedQuery);
	}

	private function createRequest(string $query, string $error): Request {
		return Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => $error,
				'payload' => $query,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
			]
		);
	}
}

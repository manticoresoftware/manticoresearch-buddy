<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\ResolveHandler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use PHPUnit\Framework\TestCase;

final class InternalBuddyClientTest extends TestCase {
	public function testGetSystemClientUsesSystemBuddyUser(): void {
		$client = new Client();
		$client->setDelegatedUser('alice');

		$method = (new ReflectionClass(ResolveHandler::class))->getMethod('getSystemClient');
		$method->setAccessible(true);
		/** @var Client $systemClient */
		$systemClient = $method->invoke(null, $client);

		$delegatedUser = (new ReflectionClass(Client::class))->getProperty('delegatedUser');
		$delegatedUser->setAccessible(true);

		$this->assertSame('system.buddy', $delegatedUser->getValue($systemClient));
		$this->assertSame('alice', $delegatedUser->getValue($client));
	}
}

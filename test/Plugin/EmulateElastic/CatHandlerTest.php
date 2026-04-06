<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\CatHandler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

final class CatHandlerTest extends TestCase {

	use TestProtectedTrait;

	public function testBuildCatIndicesInfoWildcard(): void {
		$client = $this->createMock(HTTPClient::class);
		$client->expects($this->exactly(3))
			->method('sendRequest')
			->withConsecutive(
				['SHOW TABLES'],
				['SHOW TABLE `products` STATUS'],
				['SHOW TABLE `orders` STATUS'],
			)
			->willReturnOnConsecutiveCalls(
				$this->createResponseMock(
					[
						[
							'data' => [
								['Index' => 'products'],
								['Index' => 'orders'],
							],
						],
					]
				),
				$this->createResponseMock(
					[
						[
							'data' => [
								['Variable_name' => 'indexed_documents', 'Value' => '10'],
								['Variable_name' => 'deleted_documents', 'Value' => '0'],
							],
						],
					]
				),
				$this->createResponseMock(
					[
						[
							'data' => [
								['Variable_name' => 'indexed_documents', 'Value' => '42'],
								['Variable_name' => 'deleted_documents', 'Value' => '3'],
							],
						],
					]
				),
			);

		$result = self::invokeMethod(CatHandler::class, 'buildCatIndicesInfo', [$client, '*']);
		$this->assertSame(
			[
				[
					'docs.count' => '10',
					'docs.deleted' => '0',
					'health' => 'green',
					'index' => 'products',
					'pri' => '1',
					'rep' => '1',
					'status' => 'open',
				],
				[
					'docs.count' => '42',
					'docs.deleted' => '3',
					'health' => 'green',
					'index' => 'orders',
					'pri' => '1',
					'rep' => '1',
					'status' => 'open',
				],
			],
			$result
		);
	}

	public function testBuildCatIndicesInfoByPattern(): void {
		$client = $this->createMock(HTTPClient::class);
		$client->expects($this->exactly(2))
			->method('sendRequest')
			->withConsecutive(
				['SHOW TABLES'],
				['SHOW TABLE `products` STATUS'],
			)
			->willReturnOnConsecutiveCalls(
				$this->createResponseMock(
					[
						[
							'data' => [
								['Index' => 'products'],
								['Index' => 'orders'],
							],
						],
					]
				),
				$this->createResponseMock(
					[
						[
							'data' => [
								['Variable_name' => 'indexed_documents', 'Value' => '10'],
							],
						],
					]
				),
			);

		/** @var array<array{index:string}> $result **/
		$result = self::invokeMethod(CatHandler::class, 'buildCatIndicesInfo', [$client, 'prod*']);
		$this->assertSame(1, sizeof($result));
		$this->assertSame('products', $result[0]['index']);
		/** @phpstan-ignore-next-line */
		$this->assertSame('10', $result[0]['docs.count']);
		$this->assertSame('0', $result[0]['docs.deleted']);
	}

	/**
	 * @param array<int,array<string,mixed>> $result
	 */
	private function createResponseMock(array $result): Response {
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn($result);
		return $response;
	}
}

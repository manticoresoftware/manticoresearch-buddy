<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Ds\Vector;
use Manticoresearch\Buddy\Base\Plugin\Insert\Handler;
use Manticoresearch\Buddy\Base\Plugin\Insert\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings as ManticoreSettings;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\CoreTest\Trait\TestHTTPServerTrait;
use Manticoresearch\Buddy\CoreTest\Trait\TestInEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/** @package  */
class InsertQueryHandlerTest extends TestCase {

	use TestHTTPServerTrait;
	use TestInEnvironmentTrait;

	protected function tearDown(): void {
		self::finishMockManticoreServer();
	}

	/**
	 * @param Request $networkRequest
	 * @param string $serverUrl
	 * @param string $resp
	 */
	protected function runTask(Request $networkRequest, string $serverUrl, string $resp): void {
		$payload = Payload::fromRequest($networkRequest);
		/** @var Vector<array{key:string,value:mixed}> */
		$vector = new Vector(
			[
			['key' => 'configuration_file', 'value' => '/etc/manticoresearch/manticore.conf'],
			['key' => 'worker_pid', 'value' => 7718],
			['key' => 'searchd.auto_schema', 'value' => '1'],
			['key' => 'searchd.listen', 'value' => '0.0.0:9308:http'],
			['key' => 'searchd.log', 'value' => '/var/log/manticore/searchd.log'],
			['key' => 'searchd.query_log', 'value' => '/var/log/manticore/query.log'],
			['key' => 'searchd.pid_file', 'value' => '/var/run/manticore/searchd.pid'],
			['key' => 'searchd.data_dir', 'value' => '/var/lib/manticore'],
			['key' => 'searchd.query_log_format', 'value' => 'sphinxql'],
			['key' => 'searchd.buddy_path', 'value' => 'manticore-executor /workdir/src/main.php --log-level=debug'],
			['key' => 'common.plugin_dir', 'value' => '/usr/local/lib/manticore'],
			['key' => 'common.lemmatizer_base', 'value' => '/usr/share/manticore/morph/'],
			]
		);
		$payload->setSettings(
			ManticoreSettings::fromVector($vector)
		);

		self::setBuddyVersion();
		$manticoreClient = new HTTPClient($serverUrl);
		// Force sync mode to avoid coroutines issues
		$manticoreClient->setForceSync(true);
		$handler = new Handler($payload);
		$handler->setManticoreClient($manticoreClient);
		ob_flush();
		go(
			function () use ($handler, $resp) {
				$task = $handler->run();
				$task->wait(true);
				$this->assertEquals(true, $task->isSucceed());
				/** @var Struct<string,mixed> */
				$result = $task->getResult()->getStruct();
				$this->assertEquals($resp, json_encode($result));
			}
		);
		\Swoole\Event::wait();
	}

	public function testInsertQueryExecutesProperly(): void {
		echo "\nTesting the execution of a task with INSERT query request\n";
		$resp = '[{"total":1,"error":"","warning":""}]';
		$mockServerUrl = self::setUpMockManticoreServer(false);
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => "table 'test' absent, or does not support INSERT",
				'payload' => 'INSERT INTO test(col1) VALUES(1)',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);
		$this->runTask($request, $mockServerUrl, $resp);
	}

	public function testReplaceQueryExecutesProperly(): void {
		echo "\nTesting the execution of a task with REPLACE query request\n";
		$resp = '[{"total":1,"error":"","warning":""}]';
		$mockServerUrl = self::setUpMockManticoreServer(false);
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => "table 'test' absent, or does not support INSERT",
				'payload' => 'REPLACE INTO test(col1) VALUES(1)',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);
		$this->runTask($request, $mockServerUrl, $resp);
	}
}

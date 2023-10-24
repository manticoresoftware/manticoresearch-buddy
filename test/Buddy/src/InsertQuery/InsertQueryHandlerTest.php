<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Ds\Map;
use Ds\Vector;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response as ManticoreResponse;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings as ManticoreSettings;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Response;
use Manticoresearch\Buddy\Plugin\Insert\Handler;
use Manticoresearch\Buddy\Plugin\Insert\Payload;
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use PHPUnit\Framework\TestCase;

class InsertQueryHandlerTest extends TestCase {

	use TestHTTPServerTrait;

	protected function tearDown(): void {
		self::finishMockManticoreServer();
	}

	/**
	 * @param Request $networkRequest
	 * @param string $serverUrl
	 * @param string $resp
	 */
	protected function runTask(Request $networkRequest, string $serverUrl, string $resp): void {
		$vector = new Vector();
		$vector->push(
			new Map(
				[
				'key' => 'configuration_file',
				'value' => '/etc/manticoresearch/manticore.conf',
				]
			)
		);
		$vector->push(
			new Map(
				[
				'key' => 'worker_pid',
				'value' => 7718,
				]
			)
		);
		$vector->push(
			new Map(
				[
				'key' => 'searchd.auto_schema',
				'value' => '1',
				]
			)
		);
		$vector->push(
			new Map(
				[
				'key' => 'searchd.listen',
				'value' => '0.0.0:9308:http',
				]
			)
		);
		$vector->push(
			new Map(
				[
				'key' => 'searchd.log',
				'value' => '/var/log/manticore/searchd.log',
				]
			)
		);
		$vector->push(
			new Map(
				[
				'key' => 'searchd.query_log',
				'value' => '/var/log/manticore/query.log',
				]
			)
		);
		$vector->push(
			new Map(
				[
				'key' => 'searchd.pid_file',
				'value' => '/var/run/manticore/searchd.pid',
				]
			)
		);
		$vector->push(
			new Map(
				[
				'key' => 'searchd.data_dir',
				'value' => '/var/lib/manticore',
				]
			)
		);
		$vector->push(
			new Map(
				[
				'key' => 'searchd.query_log_format',
				'value' => 'sphinxql',
				]
			)
		);
		$vector->push(
			new Map(
				[
				'key' => 'searchd.buddy_path',
				'value' => 'manticore-executor /workdir/src/main.php --debug',
				]
			)
		);
		$vector->push(
			new Map(
				[
				'key' => 'common.plugin_dir',
				'value' => '/usr/local/lib/manticore',
				]
			)
		);
		$vector->push(
			[
			'key' => 'common.lemmatizer_base',
			'value' => '/usr/share/manticore/morph/',
			]
		);
		$payload = Payload::fromRequest($networkRequest);
		$payload->setSettings(
			ManticoreSettings::fromVector($vector)
		);

		$manticoreClient = new HTTPClient(new ManticoreResponse(), $serverUrl);
		$handler = new Handler($payload);
		$handler->setManticoreClient($manticoreClient);
		ob_flush();
		go(
			function () use ($handler, $resp) {
				$task = $handler->run();
				$task->wait();

				$this->assertEquals(true, $task->isSucceed());
			/** @var Response */
				$result = $task->getResult()->getStruct();
				$this->assertEquals($resp, json_encode($result));
			}
		);
	}

	public function testInsertQueryExecutesProperly(): void {
		echo "\nTesting the execution of a task with INSERT query request\n";
		$resp = '[{"total":1,"error":"","warning":""}]';
		$mockServerUrl = self::setUpMockManticoreServer(false);
		$request = Request::fromArray(
			[
				'version' => 1,
				'error' => "table 'test' absent, or does not support INSERT",
				'payload' => 'INSERT INTO test(col1) VALUES(1)',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);
		$this->runTask($request, $mockServerUrl, $resp);
	}
}

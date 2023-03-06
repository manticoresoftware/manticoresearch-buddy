<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\InsertQuery\Executor;
use Manticoresearch\Buddy\InsertQuery\Request;
use Manticoresearch\Buddy\Lib\Task\Task;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use Manticoresearch\Buddy\Network\ManticoreClient\Response as ManticoreResponse;
use Manticoresearch\Buddy\Network\ManticoreClient\Settings as ManticoreSettings;
use Manticoresearch\Buddy\Network\Request as NetRequest;
use Manticoresearch\Buddy\Network\Response;
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use PHPUnit\Framework\TestCase;

class InsertQueryExecutorTest extends TestCase {

	use TestHTTPServerTrait;

	protected function tearDown(): void {
		self::finishMockManticoreServer();
	}

	/**
	 * @param NetRequest $networkRequest
	 * @param string $serverUrl
	 * @param string $resp
	 */
	protected function runTask(NetRequest $networkRequest, string $serverUrl, string $resp): void {
		$request = Request::fromNetworkRequest($networkRequest);
		$request->setManticoreSettings(
			ManticoreSettings::fromArray(
				[
					'configuration_file' => '/etc/manticoresearch/manticore.conf',
					'worker_pid' => 7718,
					'searchd.auto_schema' => '1',
					'searchd.listen' => '0.0.0:9308:http',
					'searchd.log' => '/var/log/manticore/searchd.log',
					'searchd.query_log' => '/var/log/manticore/query.log',
					'searchd.pid_file' => '/var/run/manticore/searchd.pid',
					'searchd.data_dir' => '/var/lib/manticore',
					'searchd.query_log_format' => 'sphinxql',
					'searchd.buddy_path' => 'manticore-executor /workdir/src/main.php --debug',
					'common.plugin_dir' => '/usr/local/lib/manticore',
					'common.lemmatizer_base' => '/usr/share/manticore/morph/',
				]
			)
		);

		$manticoreClient = new HTTPClient(new ManticoreResponse(), $serverUrl);
		$executor = new Executor($request);
		$executor->setManticoreClient($manticoreClient);
		ob_flush();
		$runtime = Task::createRuntime();
		$task = $executor->run($runtime);
		$task->wait();

		$this->assertEquals(true, $task->isSucceed());
		/** @var Response */
		$result = $task->getResult()->getMessage();
		$this->assertEquals($resp, json_encode($result));
	}

	public function testInsertQueryExecutesProperly(): void {
		echo "\nTesting the execution of a task with INSERT query request\n";
		$resp = '[{"total":1,"error":"","warning":""}]';
		$mockServerUrl = self::setUpMockManticoreServer(false);
		$request = NetRequest::fromArray(
			[
				'version' => 1,
				'error' => "table 'test' absent, or does not support INSERT",
				'payload' => 'INSERT INTO test(col1) VALUES(1)',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::CliJson,
				'path' => 'cli_json',
			]
		);
		$this->runTask($request, $mockServerUrl, $resp);
	}
}

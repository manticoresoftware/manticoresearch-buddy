<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Backup\Executor as BackupExecutor;
use Manticoresearch\Buddy\Backup\Request as BackupRequest;
use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\CommandNotAllowed;
use Manticoresearch\Buddy\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\InsertQuery\Executor as InsertQueryExecutor;
use Manticoresearch\Buddy\InsertQuery\Request as InsertQueryRequest;
use Manticoresearch\Buddy\Lib\QueryProcessor;
use Manticoresearch\Buddy\Network\ManticoreClient\Settings as ManticoreSettings;
use Manticoresearch\Buddy\Network\Request;
use Manticoresearch\Buddy\ShowQueries\Executor as ShowQueriesExecutor;
use Manticoresearch\Buddy\ShowQueries\Request as ShowQueriesRequest;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class QueryProcessorTest extends TestCase {

	use TestProtectedTrait;
	public function testCommandProcessOk(): void {
		echo "\nTesting the processing of execution command\n";
		$request = Request::fromArray(
			[
				'version' => 1,
				'error' => '',
				'payload' => 'BACKUP TO /tmp',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::CliJson,
			]
		);
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('settings', static::getManticoreSettings());
		$executor = QueryProcessor::process($request);
		$this->assertInstanceOf(BackupExecutor::class, $executor);
		$refCls = new ReflectionClass($executor);
		$request = $refCls->getProperty('request')->getValue($executor);
		$this->assertInstanceOf(BackupRequest::class, $request);

		$request = Request::fromArray(
			[
				'version' => 1,
				'error' => '',
				'payload' => 'SHOW QUERIES',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::CliJson,
			]
		);
		$executor = QueryProcessor::process($request);
		$this->assertInstanceOf(ShowQueriesExecutor::class, $executor);
		$refCls = new ReflectionClass($executor);
		$request = $refCls->getProperty('request')->getValue($executor);
		$this->assertInstanceOf(ShowQueriesRequest::class, $request);
	}

	public function testUnsupportedCommandProcessFail(): void {
		echo "\nTesting the processing of unsupported execution command\n";
		$this->expectException(SQLQueryCommandNotSupported::class);
		$this->expectExceptionMessage('Failed to handle query: Some command');
		$request = Request::fromArray(
			[
				'version' => 1,
				'error' => '',
				'payload' => 'Some command',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::CliJson,
			]
		);
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('settings', static::getManticoreSettings());
		QueryProcessor::process($request);
	}

	public function testNotAllowedCommandProcessFail(): void {
		echo "\nTesting the processing of not allowed execution command\n";

		$netRequest = Request::fromArray(
			[
				'version' => 1,
				'error' => "table 'test' absent, or does not support INSERT",
				'payload' => 'INSERT INTO test(col1) VALUES("test")',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::CliJson,
			]
		);
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('settings', static::getManticoreSettings(['searchd.auto_schema' => '1']));
		$executor = QueryProcessor::process($netRequest);
		$this->assertInstanceOf(InsertQueryExecutor::class, $executor);
		$refCls = new ReflectionClass($executor);
		$request = $refCls->getProperty('request')->getValue($executor);
		$this->assertInstanceOf(InsertQueryRequest::class, $request);

		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('settings', static::getManticoreSettings(['searchd.auto_schema' => '0']));
		$this->expectException(CommandNotAllowed::class);
		$this->expectExceptionMessage('Request handling is disabled: INSERT INTO test(col1) VALUES("test")');
		QueryProcessor::process($netRequest);
	}

	/**
	 * @param array<string,int|string> $settings
	 * @return ManticoreSettings
	 */
	protected static function getManticoreSettings(array $settings = []): ManticoreSettings {
		/**
		 * @var array{
		 * 'configuration_file'?:string,
		 * 'worker_pid'?:int,
		 * 'searchd.auto_schema'?:string,
		 * 'searchd.listen'?:string,
		 * 'searchd.log'?:string,
		 * 'searchd.query_log'?:string,
		 * 'searchd.pid_file'?:string,
		 * 'searchd.data_dir'?:string,
		 * 'searchd.query_log_format'?:string,
		 * 'searchd.buddy_path'?:string,
		 * 'searchd.binlog_path'?:string,
		 * 'common.plugin_dir'?:string,
		 * 'common.lemmatizer_base'?:string,
		 * } $settings
		 * @return static
		 */
		$settings = array_replace(
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
			],
			$settings
		);
		return ManticoreSettings::fromArray($settings);
	}
}

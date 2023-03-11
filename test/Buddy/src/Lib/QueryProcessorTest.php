<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Base\Lib\QueryProcessor;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings as ManticoreSettings;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Plugin\Backup\Handler as BackupHandler;
use Manticoresearch\Buddy\Plugin\Backup\Payload as BackupPayload;
use Manticoresearch\Buddy\Plugin\Insert\Error\AutoSchemaDisabledError;
use Manticoresearch\Buddy\Plugin\Insert\Handler as InsertQueryHandler;
use Manticoresearch\Buddy\Plugin\Insert\Payload as InsertQueryPayload;
use Manticoresearch\Buddy\Plugin\ShowQueries\Handler as ShowQueriesHandler;
use Manticoresearch\Buddy\Plugin\ShowQueries\Payload as ShowQueriesPayload;
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
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
			]
		);
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('settings', static::getSettings());
		$executor = QueryProcessor::process($request);
		$this->assertInstanceOf(BackupHandler::class, $executor);
		$refCls = new ReflectionClass($executor);
		$payload = $refCls->getProperty('payload')->getValue($executor);
		$this->assertInstanceOf(BackupPayload::class, $payload);

		$request = Request::fromArray(
			[
				'version' => 1,
				'error' => '',
				'payload' => 'SHOW QUERIES',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
			]
		);
		$handler = QueryProcessor::process($request);
		$this->assertInstanceOf(ShowQueriesHandler::class, $handler);
		$refCls = new ReflectionClass($handler);
		$payload = $refCls->getProperty('payload')->getValue($handler);
		$this->assertInstanceOf(ShowQueriesPayload::class, $payload);
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
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
			]
		);
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('settings', static::getSettings());
		QueryProcessor::process($request);
	}

	public function testNotAllowedCommandProcessFail(): void {
		echo "\nTesting the processing of not allowed execution command\n";

		$request = Request::fromArray(
			[
				'version' => 1,
				'error' => "table 'test' absent, or does not support INSERT",
				'payload' => 'INSERT INTO test(col1) VALUES("test")',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('settings', static::getSettings(['searchd.auto_schema' => '1']));
		$handler = QueryProcessor::process($request);
		$this->assertInstanceOf(InsertQueryHandler::class, $handler);
		$refCls = new ReflectionClass($handler);
		$payload = $refCls->getProperty('payload')->getValue($handler);
		$this->assertInstanceOf(InsertQueryPayload::class, $payload);

		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('settings', static::getSettings(['searchd.auto_schema' => '0']));
		$this->expectException(AutoSchemaDisabledError::class);
		QueryProcessor::process($request)->run(Task::createRuntime());
	}

	/**
	 * @param array<string,int|string> $settings
	 * @return ManticoreSettings
	 */
	protected static function getSettings(array $settings = []): ManticoreSettings {
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

<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Ds\Map;
use Ds\Vector;
use Manticoresearch\Buddy\Base\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Base\Lib\QueryProcessor;
use Manticoresearch\Buddy\Base\Plugin\Backup\Handler as BackupHandler;
use Manticoresearch\Buddy\Base\Plugin\Backup\Payload as BackupPayload;
use Manticoresearch\Buddy\Base\Plugin\Insert\Error\AutoSchemaDisabledError;
use Manticoresearch\Buddy\Base\Plugin\Insert\Handler as InsertQueryHandler;
use Manticoresearch\Buddy\Base\Plugin\Insert\Payload as InsertQueryPayload;
use Manticoresearch\Buddy\Base\Plugin\Show\Payload as ShowQueriesPayload;
use Manticoresearch\Buddy\Base\Plugin\Show\QueriesHandler as ShowQueriesHandler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings as ManticoreSettings;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\Pluggable;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class QueryProcessorTest extends TestCase {

	use TestProtectedTrait;
	public function testCommandProcessOk(): void {
		echo "\nTesting the processing of execution command\n";
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => 'BACKUP TO /tmp',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
			]
		);
		$settings = static::getSettings();
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('settings', $settings);
		$refCls->setStaticPropertyValue('pluggable', static::getPluggable($settings));
		$executor = QueryProcessor::process($request);
		$this->assertInstanceOf(BackupHandler::class, $executor);
		$refCls = new ReflectionClass($executor);
		$payload = $refCls->getProperty('payload')->getValue($executor);
		$this->assertInstanceOf(BackupPayload::class, $payload);

		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
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
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => 'Some command',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
			]
		);
		$settings = static::getSettings();
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('settings', $settings);
		$refCls->setStaticPropertyValue('pluggable', static::getPluggable($settings));
		QueryProcessor::process($request);
	}

	public function testUnsupportedCommandProcessFailWithErrorReturned(): void {
		echo "\nTesting the return of original error message for unsupported execution command\n";
		$this->expectException(SQLQueryCommandNotSupported::class);
		$this->expectExceptionMessage('Failed to handle query: Some command # error=Some error');
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => 'Some error',
				'payload' => 'Some command',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
			]
		);
		$settings = static::getSettings();
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('settings', $settings);
		$refCls->setStaticPropertyValue('pluggable', static::getPluggable($settings));
		QueryProcessor::process($request);
	}

	public function testNotAllowedCommandProcessFail(): void {
		echo "\nTesting the processing of not allowed execution command\n";

		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
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
		QueryProcessor::process($request)->run();
	}

	/**
	 * @param array<string,int|string> $settings
	 * @return ManticoreSettings
	 */
	protected static function getSettings(array $settings = []): ManticoreSettings {
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
				'value' => 'manticore-executor /workdir/src/main.php --log-level=debug',
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
			new Map(
				[
				'key' => 'common.lemmatizer_base',
				'value' => '/usr/share/manticore/morph/',
				]
			)
		);
		foreach ($settings as $key => $value) {
			$row = new Map(['key' => $key, 'value' => $value]);
			$index = $vector->find($row);
			if ($index) {
				$vector->set($index, $row);
			} else {
				$vector->push($row);
			}
		}

		return ManticoreSettings::fromVector($vector);
	}

	/**
	 * @param ManticoreSettings $settings
	 * @return Pluggable
	 */
	protected static function getPluggable(ManticoreSettings $settings): Pluggable {
		return new Pluggable($settings);
	}
}

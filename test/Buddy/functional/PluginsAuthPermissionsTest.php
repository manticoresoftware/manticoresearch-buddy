<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\ResourceTable;
use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

final class PluginsAuthPermissionsTest extends TestCase {
	use TestFunctionalTrait;

	private const string USERNAME = 'plugin_auth_user';
	private const string PASSWORD = 'plugin_auth_pass';
	private const string ROOT_PASSWORD = '12345678';
	private const string ROOT_AUTH_JSON = <<<'JSON'
{"users":[
{
"username":"root",
"salt":"46b063225b58e630487c8bc0dd6f3a4ded84884d",
"hashes":{
"password_sha1_no_salt":"7c222fb2927d828af22f592134e8932480637c0d",
"password_sha256":"3d12025ad0081a75eb675090164fd20d2ffc7c4f45f32f007a33f5253b9542d1",
"bearer_sha256":""
}
}
],"permissions":[
{"username":"root","action":"read","target":"*","allow":true},
{"username":"root","action":"write","target":"*","allow":true},
{"username":"root","action":"schema","target":"*","allow":true},
{"username":"root","action":"replication","target":"*","allow":true},
{"username":"root","action":"admin","target":"*","allow":true}
]}
JSON;

	private static string $dataDir = '';

	protected static function initConfig(): void {
		static::setManticoreConfigFile(static::$configFileName);
		self::$dataDir = \sys_get_temp_dir() . '/manticore-auth-permissions-' . bin2hex(random_bytes(8));
		$conf = str_replace("searchd {\n", "searchd {\n    auth = 1\n", static::$manticoreConf);
		$conf = str_replace(
			"data_dir = /var/lib/manticore-test\n",
			'data_dir = ' . self::$dataDir . "\n",
			$conf
		);
		static::updateManticoreConf($conf);
		static::applySearchdArgs();
	}

	protected static function startSearchd(): void {
		self::checkManticorePathes();
		file_put_contents(self::$dataDir . '/auth.json', self::ROOT_AUTH_JSON);
		chmod(self::$dataDir . '/auth.json', 0600);

		preg_match('/log = (.*?)[\r\n]/', static::$manticoreConf, $logMatches);
		$logPath = $logMatches[1] ?? '/var/log/manticore-test/searchd.log';
		system('rm -f ' . escapeshellarg($logPath));
		system('searchd --config ' . static::$manticoreConfigFilePath);
		self::$manticorePid = (int)trim((string)file_get_contents('/var/run/manticore-test/searchd.pid'));
		self::waitForBuddyReady();
	}

	/**
	 * @param array{resource:string,allowed:string,denied:string,extra_allowed?:string,extra_denied?:string} $case
	 * @dataProvider providePluginResources
	 */
	public function testResourceGrantControlsReadAndWriteAccess(array $case): void {
		$this->resetAuthState($case);
		$this->createResourceTables($case);
		$this->createUser();

		$this->assertMysqlOk($this->runRootSql("GRANT READ ON {$case['resource']} TO '" . self::USERNAME . "'"));
		$permissions = $this->runRootSql('SHOW PERMISSIONS');
		$this->assertMysqlOk($permissions);
		$this->assertStringContainsString(self::USERNAME, $permissions['output']);
		$this->assertStringContainsString($case['allowed'], $permissions['output']);

		$this->assertMysqlOk($this->runUserSql("SELECT * FROM {$case['allowed']}"));
		$this->assertMysqlPermissionDenied($this->runUserSql("SELECT * FROM {$case['denied']}"));
		$this->assertMysqlPermissionDenied(
			$this->runUserSql("UPDATE {$case['allowed']} SET marker='changed' WHERE id=1")
		);

		$this->assertMysqlOk($this->runRootSql("GRANT WRITE ON {$case['resource']} TO '" . self::USERNAME . "'"));
		$this->assertMysqlOk($this->runUserSql("UPDATE {$case['allowed']} SET marker='changed' WHERE id=1"));
		$changed = $this->runUserSql("SELECT * FROM {$case['allowed']}");
		$this->assertMysqlOk($changed);
		$this->assertStringContainsString('changed', $changed['output']);
		$this->assertMysqlPermissionDenied(
			$this->runUserSql("UPDATE {$case['denied']} SET marker='changed' WHERE id=1")
		);

		$this->assertExtraResourceAccess($case);
	}

	public function testQueueCommandsRequireMutationPermissions(): void {
		$suffix = bin2hex(random_bytes(4));
		$source = 'queue_auth_source_' . $suffix;
		$view = 'queue_auth_view_' . $suffix;
		$destination = 'queue_auth_dest_' . $suffix;

		$this->runRootSql("DROP USER '" . self::USERNAME . "'");
		$this->cleanupQueueObjects($source, $view, $destination);
		$this->createQueueObjects($source, $view, $destination);
		$this->createUser();

		$this->assertMysqlOk($this->runRootSql("GRANT READ ON source/$source TO '" . self::USERNAME . "'"));
		$this->assertMysqlOk($this->runRootSql("GRANT READ ON mva/$view TO '" . self::USERNAME . "'"));

		$this->assertMysqlOk($this->runUserSql("SHOW SOURCE $source"));
		$this->assertMysqlOk($this->runUserSql("SHOW MATERIALIZED VIEW $view"));
		$this->assertMysqlPermissionDenied(
			$this->runUserSql("ALTER MATERIALIZED VIEW $view suspended=1")
		);
		$this->assertQueueViewSuspended($view, '0');
		$this->assertMysqlPermissionDenied($this->runUserSql("DROP SOURCE $source"));
		$this->assertMysqlOk($this->runRootSql("SHOW SOURCE $source"));
		$this->assertMysqlPermissionDenied($this->runUserSql("DROP MATERIALIZED VIEW $view"));
		$this->assertMysqlOk($this->runRootSql("SHOW MATERIALIZED VIEW $view"));

		$this->cleanupQueueObjects($source, $view, $destination);
	}

	/**
	 * @return array<string,array{case:array{
	 *   resource:string,
	 *   allowed:string,
	 *   denied:string,
	 *   extra_allowed?:string,
	 *   extra_denied?:string
	 * }}>
	 */
	public static function providePluginResources(): array {
		return [
			'source' => [
				'case' => [
					'resource' => 'source/auth_allowed_source',
					'allowed' => ResourceTable::name(ResourceTable::RESOURCE_SOURCE, 'auth_allowed_source'),
					'denied' => ResourceTable::name(ResourceTable::RESOURCE_SOURCE, 'auth_denied_source'),
				],
			],
			'mva' => [
				'case' => [
					'resource' => 'mva/auth_allowed_mva',
					'allowed' => ResourceTable::name(ResourceTable::RESOURCE_MATERIALIZED_VIEW, 'auth_allowed_mva'),
					'denied' => ResourceTable::name(ResourceTable::RESOURCE_MATERIALIZED_VIEW, 'auth_denied_mva'),
				],
			],
			'chat model' => [
				'case' => [
					'resource' => 'chat model/auth_allowed_chat',
					'allowed' => ResourceTable::name(ResourceTable::RESOURCE_CHAT_MODEL, 'auth_allowed_chat'),
					'denied' => ResourceTable::name(ResourceTable::RESOURCE_CHAT_MODEL, 'auth_denied_chat'),
					'extra_allowed' => ResourceTable::name(ResourceTable::RESOURCE_CHAT_HISTORY, 'auth_allowed_chat'),
					'extra_denied' => ResourceTable::name(ResourceTable::RESOURCE_CHAT_HISTORY, 'auth_denied_chat'),
				],
			],
		];
	}

	/**
	 * @param array{allowed:string,denied:string,extra_allowed?:string,extra_denied?:string} $case
	 */
	private function resetAuthState(array $case): void {
		$this->runRootSql("DROP USER '" . self::USERNAME . "'");
		$this->dropTable($case['allowed']);
		$this->dropTable($case['denied']);
		if (!isset($case['extra_allowed'], $case['extra_denied'])) {
			return;
		}

		$this->dropTable($case['extra_allowed']);
		$this->dropTable($case['extra_denied']);
	}

	/**
	 * @param array{allowed:string,denied:string,extra_allowed?:string,extra_denied?:string} $case
	 */
	private function createResourceTables(array $case): void {
		$this->createResourceTable($case['allowed'], 'allowed');
		$this->createResourceTable($case['denied'], 'denied');
		if (!isset($case['extra_allowed'], $case['extra_denied'])) {
			return;
		}

		$this->createResourceTable($case['extra_allowed'], 'allowed_history');
		$this->createResourceTable($case['extra_denied'], 'denied_history');
	}

	/**
	 * @param array{extra_allowed?:string,extra_denied?:string} $case
	 */
	private function assertExtraResourceAccess(array $case): void {
		if (!isset($case['extra_allowed'], $case['extra_denied'])) {
			return;
		}

		$this->assertMysqlOk($this->runUserSql("SELECT * FROM {$case['extra_allowed']}"));
		$this->assertMysqlPermissionDenied($this->runUserSql("SELECT * FROM {$case['extra_denied']}"));
		$this->assertMysqlOk($this->runUserSql("UPDATE {$case['extra_allowed']} SET marker='changed' WHERE id=1"));
		$this->assertMysqlPermissionDenied(
			$this->runUserSql("UPDATE {$case['extra_denied']} SET marker='changed' WHERE id=1")
		);
	}

	private function createResourceTable(string $tableName, string $marker): void {
		if (str_starts_with($tableName, ResourceTable::TABLE_PREFIX_SOURCE)) {
			$this->createSourceResourceTable($tableName, $marker);
			return;
		}

		if (str_starts_with($tableName, ResourceTable::TABLE_PREFIX_MATERIALIZED_VIEW)) {
			$this->createMaterializedViewResourceTable($tableName, $marker);
			return;
		}

		$this->assertMysqlOk(
			$this->runRootSql(
				"CREATE TABLE $tableName(title text, marker string); " .
				"INSERT INTO $tableName(id,title,marker) VALUES(1,'row','$marker')"
			)
		);
	}

	private function createSourceResourceTable(string $tableName, string $marker): void {
		$name = substr($tableName, strlen(ResourceTable::TABLE_PREFIX_SOURCE));
		$this->assertMysqlOk(
			$this->runRootSql(
				"CREATE TABLE $tableName(" .
				'id bigint, type text, name text attribute indexed, full_name text, ' .
				'buffer_table text, attrs json, custom_mapping json, original_query text, marker string); ' .
				"INSERT INTO $tableName" .
				'(id,type,name,full_name,buffer_table,attrs,custom_mapping,original_query,marker) ' .
				"VALUES(1,'kafka','$name','{$name}_0','system.buffer_{$name}_0','{}','{}'," .
				"'CREATE SOURCE $name (id bigint)','$marker')"
			)
		);
	}

	private function createMaterializedViewResourceTable(string $tableName, string $marker): void {
		$name = substr($tableName, strlen(ResourceTable::TABLE_PREFIX_MATERIALIZED_VIEW));
		$this->assertMysqlOk(
			$this->runRootSql(
				"CREATE TABLE $tableName(" .
				'id bigint, name text attribute indexed, source_name text, destination_name text, ' .
				'query text, original_query text, suspended bool, marker string); ' .
				"INSERT INTO $tableName(id,name,source_name,destination_name,query,original_query,suspended,marker) " .
				"VALUES(1,'$name','source_0','destination','SELECT * FROM source_0'," .
				"'CREATE MATERIALIZED VIEW $name TO destination AS SELECT * FROM source',0,'$marker')"
			)
		);
	}

	private function createQueueObjects(string $source, string $view, string $destination): void {
		$this->assertMysqlOk(
			$this->runRootSql(
				"CREATE SOURCE $source (id bigint, body text) " .
				"type='kafka' broker_list='127.0.0.1:9092' topic_list='queue-auth' " .
				"consumer_group='queue-auth' num_consumers='1' batch='50'"
			)
		);
		$this->assertMysqlOk($this->runRootSql("CREATE TABLE $destination (id bigint, body text)"));
		$this->assertMysqlOk(
			$this->runRootSql(
				"CREATE MATERIALIZED VIEW $view TO $destination AS SELECT id, body FROM $source"
			)
		);
	}

	private function cleanupQueueObjects(string $source, string $view, string $destination): void {
		$this->runRootSql("DROP MATERIALIZED VIEW $view");
		$this->runRootSql("DROP SOURCE $source");
		$this->runRootSql("DROP TABLE IF EXISTS $destination");
	}

	private function assertQueueViewSuspended(string $view, string $expected): void {
		$result = $this->runRootSql("SHOW MATERIALIZED VIEW $view");
		$this->assertMysqlOk($result);
		$this->assertStringContainsString($expected, $result['output']);
	}

	private function dropTable(string $tableName): void {
		$this->runRootSql("DROP TABLE IF EXISTS $tableName");
	}

	private function createUser(): void {
		$this->assertMysqlOk(
			$this->runRootSql("CREATE USER '" . self::USERNAME . "' IDENTIFIED BY '" . self::PASSWORD . "'")
		);
	}

	/**
	 * @return array{code:int,output:string}
	 */
	private function runRootSql(string $query): array {
		return self::runMysql('root', self::ROOT_PASSWORD, $query);
	}

	/**
	 * @return array{code:int,output:string}
	 */
	private function runUserSql(string $query): array {
		return self::runMysql(self::USERNAME, self::PASSWORD, $query);
	}

	/**
	 * @return array{code:int,output:string}
	 */
	private static function runMysql(string $user, string $password, string $query): array {
		$payloadFile = \sys_get_temp_dir() . '/payload-' . uniqid() . '.data';
		file_put_contents($payloadFile, $query . ';');
		$command = sprintf(
			'mysql -P%d -h127.0.0.1 -u%s -p%s < %s 2>&1',
			static::getListenSqlPort(),
			escapeshellarg($user),
			escapeshellarg($password),
			escapeshellarg($payloadFile)
		);
		exec($command, $output, $code);
		unlink($payloadFile);

		return ['code' => $code, 'output' => implode(PHP_EOL, $output)];
	}

	/**
	 * @param array{code:int,output:string} $result
	 */
	private function assertMysqlOk(array $result): void {
		$this->assertSame(0, $result['code'], $result['output']);
		$this->assertStringNotContainsString('ERROR ', $result['output']);
	}

	/**
	 * @param array{code:int,output:string} $result
	 */
	private function assertMysqlPermissionDenied(array $result): void {
		$this->assertNotSame(0, $result['code'], $result['output']);
		$this->assertStringContainsString('Permission denied', $result['output']);
	}
}

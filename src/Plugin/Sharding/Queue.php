<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Ds\Vector;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use RuntimeException;

/**
 * @phpstan-type QueryItem array{
 *  id:int,
 *  query:string,
 *  wait_for_id:int,
 *  tries:int,
 *  status:string,
 *  created_at:int,
 *  updated_at:int
 * }
 */
final class Queue {
	const MAX_TRIES = 10;

	public readonly string $table;

	protected int $waitForId = 0;

	/**
	 * Initialize the state with current client that used to get all data
	 * @param Client $client
	 * @return void
	 */
	public function __construct(
		protected Cluster $cluster,
		protected Client $client
	) {
		$this->table = 'system.sharding_queue';
	}

	/**
	 * This is method that helps us to maintain same wait for id
	 * @param int $waitForId
	 * @return static
	 */
	public function setWaitForId(int $waitForId): static {
		$this->waitForId = $waitForId;
		return $this;
	}

	/**
	 * Reset the wait for id to 0
	 * @return static
	 */
	public function resetWaitForId(): static {
		$this->waitForId = 0;
		return $this;
	}

	/**
	 * Add new query for requested node to the queue
	 * @param string $nodeId
	 * @param string $query
	 * @return int the queue id
	 */
	public function add(string $nodeId, string $query): int {
		$table = $this->cluster->getSystemTableName($this->table);
		$mt = (int)(microtime(true) * 1000);
		$query = addcslashes($query, "'");
		$id = hrtime(true);
		$this->client->sendRequest(
			"
			INSERT INTO {$table}
				(`id`, `node`, `query`, `wait_for_id`, `tries`, `status`, `created_at`, `updated_at`, `duration`)
			VALUES
				($id, '{$nodeId}', '{$query}', $this->waitForId, 0,'created', {$mt}, {$mt}, 0)
			"
		);
		return $id;
	}

	/**
	 * Get the single row by id
	 * @param  int    $id
	 * @return QueryItem|array{}
	 */
	public function getById(int $id): array {
		$table = $this->cluster->getSystemTableName($this->table);

		$q = "SELECT * FROM {$table} WHERE id = {$id} LIMIT 1";
		/** @var array{0:array{data:array<QueryItem>}} */
		$res = $this->client->sendRequest($q)->getResult();
		return $res[0]['data'][0] ?? [];
	}

	/**
	 * Process the queue for node
	 * @param Node $node
	 * @return void
	 */
	public function process(Node $node): void {
		$queries = $this->dequeue($node);
		foreach ($queries as $query) {
			// If the query is not ready to be repeated, we just return
			if ($this->shouldSkipQuery($query)) {
				return;
			}

			// In case current query is not ok, we just return and repeat later
			if (!$this->handleQuery($node, $query)) {
				return;
			}
		}
	}

	/**
	 * Helper to check if we should skip query in processing the queue
	 * @param  QueryItem $query
	 * @return bool
	 */
	protected function shouldSkipQuery(array $query): bool {
		if ($query['wait_for_id']) {
			$waitFor = $this->getById($query['wait_for_id']);
			if ($waitFor && $waitFor['status'] !== 'processed') {
				Buddy::debugv("Sharding queue: wait for {$query['wait_for_id']} [{$waitFor['status']}]");
				return true;
			}
		}

		// Do the delay check only for queries that were processed at least once
		// We check with created_at cuz join cluster queries that are normally failing
		// takes a lot of time to be processed and we do not want to delay them on repeat
		if ($query['tries'] > 0) {
			$timeSinceLastAttempt = (int)(microtime(true) * 1000) - $query['created_at'];
			$maxAttemptTime = (int)ceil(pow(1.21, $query['tries']) * 1000);
			if ($timeSinceLastAttempt < $maxAttemptTime) {
				Buddy::debugv(
					"Sharding queue: delay {$query['id']} with {$query['tries']} tries"
						." due to {$timeSinceLastAttempt}ms < {$maxAttemptTime}ms"
				);
				return true;
			}
		}

		return !$this->attemptToUpdateStatus($query, 'processing', 0);
	}

	/**
	 * Helper to process the query from the queue
	 * @param  Node   $node
	 * @param  QueryItem  $query
	 * @return bool
	 */
	protected function handleQuery(Node $node, array $query): bool {
		$mt = microtime(true);
		Buddy::debugv("[{$node->id}] Queue query: {$query['query']}");

		$res = $this->executeQuery($query);
		$status = empty($res['error']) ? 'processed' : 'error';

		Buddy::debugv("[{$node->id}] Queue query result [$status]: " . json_encode($res));

		$duration = (int)((microtime(true) - $mt) * 1000);
		$this->attemptToUpdateStatus($query, $status, $duration);

		if ($status !== 'error') {
			return true;
		}

		Buddy::info("[$node->id] Queue query error: {$query['query']}");
		return false;
	}

	/**
	 * Execute query and return info
	 * @param  QueryItem  $query
	 * @return Struct<int|string, mixed>
	 */
	protected function executeQuery(array $query): Struct {
		// TODO: this is a temporary hack, remove when job is done on searchd
		$this->runMkdir($query['query']);
		return $this->client->sendRequest($query['query'])->getResult();
	}

	/**
	 * Try to update status with log to the debug
	 * @param QueryItem $query
	 * @param string $status
	 * @param int $duration
	 * @return bool
	 */
	protected function attemptToUpdateStatus(array $query, string $status, int $duration): bool {
		$isOk = $this->updateStatus($query['id'], $status, $query['tries'] + 1, $duration);
		if ($isOk) {
			return true;
		}

		Buddy::debugv("Failed to update queue status for {$query['id']}");
		return false;
	}

	/**
	 * Dequeue the queries to execute for node and return vector of queries
	 * We use this method for internal use only
	 * and automatic handle returns of failed queries
	 * @param  Node   $node
	 * @return Vector<QueryItem>
	 *  list of queries for request node
	 */
	protected function dequeue(Node $node): Vector {
		$maxTries = static::MAX_TRIES;
		$query = "
		SELECT * FROM {$this->table}
			WHERE
				`node` = '{$node->id}'
				 AND
				`status` <> 'processed'
				AND
				`tries` < {$maxTries}
			ORDER BY `id` ASC
			LIMIT 1000
		";

		/** @var array{0?:array{data?:array<array<mixed>>}} */
		$res = $this->client->sendRequest($query)->getResult();
		$queries = new Vector;

		if (!isset($res[0]['data'])) {
			return $queries;
		}
		foreach ($res[0]['data'] as $row) {
			$queries->push($row);
		}
		return $queries;
	}

	/**
	 * Update status of the queued query by its id
	 * @param int $id
	 * @param string $status
	 * @param int $tries
	 * @return bool
	 */
	protected function updateStatus(int $id, string $status, int $tries, int $duration = 0): bool {
		$table = $this->cluster->getSystemTableName($this->table);
		$mt = (int)(microtime(true) * 1000);
		$update = [
			"`status` = '{$status}'",
			"`tries` = {$tries}",
			"`updated_at` = {$mt}",
			"`duration` = {$duration}",
		];

		$rows = implode(', ', $update);
		$q = "UPDATE {$table} SET {$rows} WHERE `id` = {$id}";
		/** @var array{0:array{error:string}}|array{error:string} $result */
		$result = $this->client->sendRequest($q)->getResult();
		$error = $result[0]['error'] ?? ($result['error'] ?? '');
		return !$error;
	}

	/**
	 * Setup the initial tables for the system cluster
	 * @return void
	 */
	public function setup(): void {
		$hasTable = $this->client->hasTable($this->table);
		if ($hasTable) {
			throw new RuntimeException(
				'Trying to initialize while already initialized.'
			);
		}
		$query = "CREATE TABLE {$this->table} (
			`node` string,
			`query` string,
			`wait_for_id` bigint,
			`tries` int,
			`status` string,
			`created_at` bigint,
			`updated_at` bigint,
			`duration` int
		)";
		$this->client->sendRequest($query);
		$this->cluster->attachTables($this->table);
	}

	/**
	 * @param string $query
	 * @return void
	 */
	protected function runMkdir(string $query): void {
		if (!stripos($query, 'as path')) {
			return;
		}

		preg_match("/'([^']+)' as path/ius", $query, $m);
		if (!$m) {
			return;
		}

		$settings = $this->client->getSettings();
		$dir = $settings->searchdDataDir . '/' . $m[1];
		if (is_dir($dir)) {
			return;
		}

		mkdir($dir, 0755);
	}
}

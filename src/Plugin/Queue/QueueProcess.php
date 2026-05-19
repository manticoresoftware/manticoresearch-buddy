<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\ResourceTable;
use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source\BaseCreateSourceHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka\KafkaWorker;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Process\BaseProcessor;
use Manticoresearch\Buddy\Core\Process\Process;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Throwable;

class QueueProcess extends BaseProcessor {
	use InternalBuddyClientTrait;

	/** @return array{0: callable, 1: int}[]  */
	public function start(): array {
		parent::start();

		$this->runPool();
		return [];
	}

	/** @return void  */
	public function stop(): void {
		parent::stop();
	}

	/**
	 * This is just initialization method and should not be invoked outside
	 * @throws ManticoreSearchClientError
	 * @throws GenericError
	 * @throws \Exception
	 */
	public function runPool(): void {
		$systemClient = self::getSystemClient($this->client);

		$sourceTables = ResourceTable::list($systemClient, ResourceTable::TABLE_PREFIX_SOURCE);
		if ($sourceTables === []) {
			Buddy::debug('Queue source table does not exist. Exiting queue process pool');
			return;
		}

		$viewTables = ResourceTable::list($systemClient, ResourceTable::TABLE_PREFIX_MATERIALIZED_VIEW);
		if ($viewTables === []) {
			Buddy::debug('Queue views table does not exist. Exiting queue process pool');
			return;
		}

		foreach ($sourceTables as $sourceTable) {
			$this->runSourceTablePool($systemClient, $sourceTable, $viewTables);
		}
		unset($systemClient);
	}

	/**
	 * @param list<string> $viewTables
	 * @throws GenericError
	 */
	private function runSourceTablePool(Client $systemClient, string $sourceTable, array $viewTables): void {
		$sql = /** @lang ManticoreSearch */
			"SELECT * FROM $sourceTable " .
			"WHERE match('@type \"" . BaseCreateSourceHandler::SOURCE_TYPE_KAFKA . "\"') LIMIT 99999";
		$results = $systemClient->sendRequest($sql);

		if ($results->hasError()) {
			Buddy::debug("Can't get sources. Exit worker pool. Reason: " . $results->getError());
			return;
		}

		if (!is_array($results->getResult()[0])) {
			return;
		}

		foreach ($results->getResult()[0]['data'] as $instance) {
			$this->runSourceInstance($systemClient, $instance, $viewTables);
		}
	}

	/**
	 * @param array<string, string> $instance
	 * @param list<string> $viewTables
	 * @throws GenericError
	 */
	private function runSourceInstance(Client $systemClient, array $instance, array $viewTables): void {
		$viewSearchResult = $this->findViewBySourceName($systemClient, $viewTables, (string)$instance['full_name']);
		if ($viewSearchResult === null) {
			Buddy::debug("Can't find view with source_name {$instance['full_name']}");
			return;
		}

		if (!empty($viewSearchResult['data'][0]['suspended'])) {
			Buddy::debugvv("Worker {$instance['full_name']} is suspended. Skip running");
			return;
		}

		$instance['destination_name'] = $viewSearchResult['data'][0]['destination_name'];
		$instance['query'] = $viewSearchResult['data'][0]['query'];
		$this->execute('runWorker',	[$instance]);
	}

	/**
	 * @param list<string> $viewTables
	 * @return array{data:array<int,array<string,string>>}|null
	 * @throws GenericError
	 */
	private function findViewBySourceName(Client $systemClient, array $viewTables, string $sourceName): ?array {
		foreach ($viewTables as $viewTable) {
			$sql = /** @lang ManticoreSearch */
				"SELECT * FROM $viewTable WHERE match('@source_name \"$sourceName\"')";
			$results = $systemClient->sendRequest($sql);

			if ($results->hasError()) {
				throw GenericError::create('Error during getting view. Exit worker. Reason: ' . $results->getError());
			}

			$results = $results->getResult();
			/** @var array{data:array<int,array<string,string>>} $viewSearchResult */
			$viewSearchResult = $results[0];
			if (!isset($viewSearchResult['data'][0])) {
				continue;
			}

			return $viewSearchResult;
		}

		return null;
	}

	/**
	 * TODO: declare type and info
	 * This method also can be called with execute method of the processor from the Handler
	 *
	 * @param array{
	 *    full_name:string,
	 *    buffer_table:string,
	 *    destination_name:string,
	 *    query:string,
	 *    custom_mapping: string,
	 *    attrs:string } $instance
	 * @param bool $shouldStart
	 * @throws \Exception
	 */
	public function runWorker(array $instance, bool $shouldStart = true): void {
		Buddy::debugvv('Start worker ' . $instance['full_name']);
		try {
			$systemClient = self::getSystemClient($this->client);
			$kafkaWorker = new KafkaWorker($systemClient, $instance);
			unset($systemClient);
			$worker = Process::createWorker($kafkaWorker, $instance['full_name']);
			// Add worker to the pool and automatically start it
			$this->process->addWorker($worker, $shouldStart);
		} catch (\Exception $exception) {
			Buddy::error($exception);
		}

		// When we need to use this method from the Handler
		// we simply get processor and execute method with parameters
		// processor->execute('runWorker', [...args])
		// That will
	}

	/**
	 * @throws \Exception
	 */
	public function stopWorkerById(string $id): bool {
		try {
			$worker = $this->process->getWorker($id);
			$this->process->removeWorker($worker);
		} catch (Throwable $exception) {
			Buddy::debugvv($exception->getMessage());
		} finally {
			return true;
		}
	}
}

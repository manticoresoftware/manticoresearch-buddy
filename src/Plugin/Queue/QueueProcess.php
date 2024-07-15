<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source\BaseCreateSourceHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka\KafkaWorker;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Process\BaseProcessor;
use Manticoresearch\Buddy\Core\Process\Process;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Throwable;

class QueueProcess extends BaseProcessor {
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

		if (!$this->client->hasTable(Payload::SOURCE_TABLE_NAME)) {
			Buddy::debug('Queue source table does not exist. Exiting queue process pool');
			return;
		}

		if (!$this->client->hasTable(Payload::VIEWS_TABLE_NAME)) {
			Buddy::debug('Queue views table does not exist. Exiting queue process pool');
			return;
		}

		$sql = /** @lang ManticoreSearch */
			'SELECT * FROM ' . Payload::SOURCE_TABLE_NAME .
			" WHERE match('@type \"" . BaseCreateSourceHandler::SOURCE_TYPE_KAFKA . "\"') LIMIT 99999";
		$results = $this->client->sendRequest($sql);

		if ($results->hasError()) {
			Buddy::debug("Can't get sources. Exit worker pool. Reason: " . $results->getError());
			return;
		}

		if (!is_array($results->getResult()[0])) {
			return;
		}

		foreach ($results->getResult()[0]['data'] as $instance) {
			$sql = /** @lang ManticoreSearch */
				'SELECT * FROM ' . Payload::VIEWS_TABLE_NAME .
				" WHERE match('@source_name \"{$instance['full_name']}\"')";
			$results = $this->client->sendRequest($sql);

			if ($results->hasError()) {
				throw GenericError::create('Error during getting view. Exit worker. Reason: ' . $results->getError());
			}

			$results = $results->getResult();
			if (is_array($results[0]) && !isset($results[0]['data'][0])) {
				Buddy::debug("Can't find view with source_name {$instance['full_name']}");
				continue;
			}

			if (!empty($results[0]['data'][0]['suspended'])) {
				Buddy::debugv("Worker {$instance['full_name']} is suspended. Skip running");
				continue;
			}

			$instance['destination_name'] = $results[0]['data'][0]['destination_name'];
			$instance['query'] = $results[0]['data'][0]['query'];
			$this->execute('runWorker',	[$instance]);
		}
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
	 *    attrs:string } $instance
	 * @param bool $shouldStart
	 * @throws \Exception
	 */
	public function runWorker(array $instance, bool $shouldStart = true): void {

		Buddy::debugv('Start worker ' . $instance['full_name']);
		$kafkaWorker = new KafkaWorker($this->client, $instance);
		$worker = Process::createWorker($kafkaWorker, $instance['full_name']);
		// Add worker to the pool and automatically start it
		$this->process->addWorker($worker, $shouldStart);

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
		} catch (Throwable) {
		} finally {
			return true;
		}
	}
}

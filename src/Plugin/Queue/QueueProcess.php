<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source\BaseCreateSourceHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\View\CreateViewHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka\KafkaWorker;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Process\BaseProcessor;
use Manticoresearch\Buddy\Core\Process\Process;
use Manticoresearch\Buddy\Core\Tool\Buddy;

class QueueProcess extends BaseProcessor
{


	protected static $instance;
	private Client $client;

	private function __clone() {
	}


	public static function getInstance(): QueueProcess {
		if (self::$instance === null) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public function setClient(Client $client): QueueProcess {
		self::getInstance()->client = $client;
		return self::getInstance();
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws GenericError
	 */
	public function start(): void {
		parent::start();
		self::getInstance()->runPool();
	}

	public function stop(): void {
		var_dump('stopping');
		parent::stop();
	}

	/**
	 * This is just initialization method and should not be invoked outside
	 * @throws ManticoreSearchClientError
	 * @throws GenericError
	 * @throws \Exception
	 */
	protected function runPool(): void {

		if (!$this->client->hasTable(BaseCreateSourceHandler::SOURCE_TABLE_NAME)){
			Buddy::debugv("Queue source table not exist. Exit queue process pool");
			return;
		}

		if (!$this->client->hasTable(CreateViewHandler::VIEWS_TABLE_NAME)){
			Buddy::debugv("Queue views table not exist. Exit queue process pool");
			return;
		}

		$sql = /** @lang ManticoreSearch */
			'SELECT * FROM ' . BaseCreateSourceHandler::SOURCE_TABLE_NAME .
			" WHERE match('@name \"" . BaseCreateSourceHandler::SOURCE_TYPE_KAFKA . "\"') LIMIT 99999";
		$results = $this->client->sendRequest($sql);

		if ($results->hasError()) {
			Buddy::debug("Can't get sources. Exit worker pool. Reason: " . $results->getError());
			return;
		}

		foreach ($results->getResult()[0]['data'] as $instance) {
			$sql = /** @lang ManticoreSearch */
				'SELECT * FROM ' . CreateViewHandler::VIEWS_TABLE_NAME .
				" WHERE match('@source_name \"{$instance['full_name']}\"')";
			$results = $this->client->sendRequest($sql);

			if ($results->hasError()) {
				throw GenericError::create('Error during getting view. Exit worker. Reason: ' . $results->getError());
			}

			$results = $results->getResult();
			if (!isset($results[0]['data'][0])) {
				Buddy::debugv("Can't find view with source_name {$instance['full_name']}");
				continue;
			}

			if (!empty($results[0]['data'][0]['suspended'])) {
				Buddy::debugv("Worker {$instance['full_name']} is suspended. Skip running");
				continue;
			}

			$instance['destination_name'] = $results[0]['data'][0]['destination_name'];
			$instance['query'] = $results[0]['data'][0]['query'];
			echo "++++++++++++++++ ". $instance['full_name']."\n";
			$this->runWorker($instance);
		}
	}

	// TODO: declare type and info
	// This method also can be called with execute method of the processor from the Handler
	/**
	 * @throws \Exception
	 */
	public function runWorker(array $instance, $shouldStart = true): void {
		$workerFn = function () use ($instance): void {
			Buddy::debugv('------->> Start worker ' . $instance['full_name']);
			$kafkaWorker = new KafkaWorker($this->client, $instance);
			$kafkaWorker->run();
		};

		// Add worker to the pool and automatically start it
		$this->process->addWorker(Process::createWorker($workerFn), $shouldStart);

		// When we need to use this method from the Handler
		// we simply get processor and execute method with parameters
		// processor->execute('runWorker', [...args])
		// That will
	}

	/**
	 * @throws \Exception
	 */
	public function stopWorkerById(string $id): bool {
		$worker = self::getInstance()->getProcess()->getWorker($id);
		if ($worker === null) {
			return true;
		}
		self::getInstance()->getProcess()->removeWorker($worker);
		return true;
	}
}

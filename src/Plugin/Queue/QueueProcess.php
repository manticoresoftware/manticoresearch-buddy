<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source\BaseCreateSourceHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka\KafkaWorker;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Process\BaseProcessor;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use RdKafka\Exception;

class QueueProcess extends BaseProcessor
{
	private Client $client;

	public function setClient(Client $client): static {
		$this->client = $client;
		return $this;
	}

	public function start(): void {
		parent::start();
		$this->runPool();
	}

	public function stop(): void {
		var_dump('stopping');
		parent::stop();
	}

	/**
	 * This is just initialization method and should not be invoked outside
	 * @throws ManticoreSearchClientError
	 * @throws Exception
	 */
	protected function runPool(): void {
		$sql = /** @lang ManticoreSearch */
			'SELECT * FROM ' . BaseCreateSourceHandler::SOURCE_TABLE_NAME .
			" WHERE match('@name \"" . BaseCreateSourceHandler::SOURCE_TYPE_KAFKA . "\"')";
		$results = $this->client->sendRequest($sql);

		if ($results->hasError()) {
			Buddy::debug("Can't get sources. Exit worker pool. Reason: " . $results->getError());
			return;
		}

		foreach ($results->getResult()[0]['data'] as $instance) {
			$attrs = json_decode($instance['attrs'], true);

			$this->runWorker($instance, $attrs);
		}
	}

	// TODO: declare type and info
	// This method also can be called with execute method of the processor from the Handler
	public function runWorker($instance, $attrs): void {
		$workerFn = function () use ($instance, $attrs): void {
			Buddy::debugv('------->> Start worker ' . $instance['full_name']);
			$kafkaWorker = new KafkaWorker(
				$instance['full_name'],
				$this->client, $attrs['broker'], $attrs['topic'],
				$attrs['group'], $instance['buffer_table'], (int)$attrs['batch']
			);
			$kafkaWorker->run();
		};

		// Add worker to the pool and automatically start it
		$this->process->addWorker($workerFn, true);

		// When we need to use this method from the Handler
		// we simply get processor and execute method with parameters
		// processor->execute('runWorker', [...args])
		// That will
	}
}

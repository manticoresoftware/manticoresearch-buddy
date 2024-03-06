<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Manticoresearch\Buddy\Base\Plugin\Queue\SourceHandlers\SourceHandler;
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
		$this->execute('runPool');
	}

	public function stop(): void {
		var_dump('stopping');
		parent::stop();
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws Exception
	 */
	public function runPool(): void {
		$sql = /** @lang ManticoreSearch */
			'SELECT * FROM ' . SourceHandler::SOURCE_TABLE_NAME .
			" WHERE match('@name \"" . SourceHandler::SOURCE_TYPE_KAFKA . "\"')";
		$results = $this->client->sendRequest($sql);

		if ($results->hasError()) {
			Buddy::debug("Can't get sources. Exit worker pool. Reason: " . $results->getError());
			return;
		}

		foreach ($results->getResult()[0]['data'] as $instance) {
			$desc = $this->client->sendRequest('DESC ' . $instance['buffer_table']);

			if ($desc->hasError()) {
				Buddy::debug("Can't describe table ". $instance['buffer_table'] .'. Reason: '. $desc->getError());
				continue;
			}

			$attrs = json_decode($instance['attrs'], true);

			$fields = [];
			foreach ($desc->getResult()[0]['data'] as $field) {
				$fields[$field['Field']] = $field['Type'];
			}

			go(
				function () use ($instance, $attrs, $fields) {
					$kafkaWorker = new KafkaWorker($instance['full_name'],
						$this->client, $attrs['broker'], $attrs['topic'],
						$attrs['group'], $instance['buffer_table'], $fields
					);
					$kafkaWorker->run();
				}
			);
		}
	}
}

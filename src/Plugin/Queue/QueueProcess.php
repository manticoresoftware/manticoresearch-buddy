<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Manticoresearch\Buddy\Base\Plugin\Queue\SourceHandlers\SourceHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Workers\KafkaWorker;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Process\BaseProcessor;
use Manticoresearch\Buddy\Core\Tool\Buddy;

class QueueProcess extends BaseProcessor
{
	private Client $client;

	public function setClient(Client $client): static {
		$this->client = $client;
		return $this;
	}

	public function start(): void {
		Buddy::debug('-----> Queue process: start');
		parent::start();
		$this->execute('runPool');
	}

	public function stop(): void {
		var_dump('stopping');
		parent::stop();
	}

	public function runPool(): void {
		Buddy::debug('+++++++');
		$sql = /** @lang ManticoreSearch */
			'SELECT * FROM ' . SourceHandler::SOURCE_TABLE_NAME .
			" match('@name \"" . SourceHandler::SOURCE_TYPE_KAFKA . "\"')";
		$results = $this->client->sendRequest($sql);

		if ($results->hasError()) {
			Buddy::debug("Can't get sources. Exit worker pool. Reason: " . $results->getError());
			return;
		}

		foreach ($results->getResult() as $instance) {
			$desc = $this->client->sendRequest('DESC ' . $instance['buffer_table']);

			if ($desc->hasError()) {
				Buddy::debug("Can't describe table ". $instance['buffer_table'] .'. Reason: '. $desc->getError());
				continue;
			}

			// id, type, name, buffer_table, group_offset, attrs
			go(
				function () use ($instance) {
					$kafkaWorker = new KafkaWorker(
						$this->client, $instance['attrs']['broker'], $instance['attrs']['topic'], $instance['attrs']['group']
					);
					$kafkaWorker->run();
				}
			);
		}
	}
}

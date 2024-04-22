<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka;

use Manticoresearch\Buddy\Base\Plugin\Queue\StringFunctionsTrait;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Fields;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Lib\BuddyRequestError;
use RdKafka\Conf;
use RdKafka\Exception;
use RdKafka\KafkaConsumer;

class KafkaWorker {
	use StringFunctionsTrait;

	private Client $client;

	private string $brokerList;

	/** @var array|string[] */
	private array $topicList;
	private string $consumerGroup;

	private string $bufferTable;
	private int $batchSize;

	private View $view;


	/**
	 * @param Client $client
	 * @param array{
	 *   full_name:string,
	 *   buffer_table:string,
	 *   destination_name:string,
	 *   query:string,
	 *   attrs:string } $instance
	 *
	 * @throws GenericError
	 */
	public function __construct(
		Client $client,
		array  $instance
	) {

		/** @var array{group:string, broker:string, topic:string, batch:string } $attrs */
		$attrs = json_decode($instance['attrs'], true);

		$this->client = $client;
		$this->consumerGroup = $attrs['group'];
		$this->brokerList = $attrs['broker'];
		$this->bufferTable = $instance['buffer_table'];
		$this->topicList = array_map(fn($item) => trim($item), explode(',', $attrs['topic']));
		$this->batchSize = (int)$attrs['batch'];
		$this->view = new View(
			$this->client,
			$this->bufferTable,
			$instance['destination_name'],
			$instance['query']
		);
		$this->getFields($this->client, $this->bufferTable);
	}

	/**
	 * @return Conf
	 */

	private function initKafkaConfig(): Conf {
		$conf = new Conf();
		$conf->set('group.id', $this->consumerGroup);
		$conf->set('metadata.broker.list', $this->brokerList);
		$conf->set('enable.auto.commit', 'false');

		// Set where to start consuming messages when there is no initial offset in
		// offset store or the desired offset is out of range.
		// 'earliest': start from the beginning
		$conf->set('auto.offset.reset', 'earliest');

		// Emit EOF event when reaching the end of a partition
		$conf->set('enable.partition.eof', 'true');

		// Set a rebalance callback to log partition assignments (optional)
		$conf->setRebalanceCb(
			function (KafkaConsumer $kafka, $err, array $partitions = null) {
				switch ($err) {
					case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
						$kafka->assign($partitions);
						break;

					case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
						$kafka->assign(null);
						break;

					default:
						throw new \Exception($err);
				}
			}
		);
		return $conf;
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	public function run(): void {
		$batch = new Batch($this->batchSize);
		$batch->setCallback(
			function ($batch) {
				return $this->processBatch($batch);
			}
		);


		$conf = $this->initKafkaConfig();

		$consumer = new KafkaConsumer($conf);
		$consumer->subscribe($this->topicList);

		$lastFullMessage = null;
		while (true) {
			$message = $consumer->consume(1000);
			switch ($message->err) {
				case RD_KAFKA_RESP_ERR_NO_ERROR:
					$lastFullMessage = $message;
					if ($batch->add($message->payload)) {
						$consumer->commit($message);
					}
					break;
				case RD_KAFKA_RESP_ERR__PARTITION_EOF:
					break;
				case RD_KAFKA_RESP_ERR__TIMED_OUT:
					if ($batch->checkProcessingTimeout() && $batch->process() && $lastFullMessage !== null) {
						$consumer->commit($lastFullMessage);
					}
					break;
				default:
					throw new \Exception($message->errstr(), $message->err);
			}
		}
	}

	/**
	 * @param array<int, string> $batch
	 * @return bool
	 * @throws GenericError
	 */
	public function processBatch(array $batch): bool {
		if ($this->insertToBuffer($this->mapMessages($batch))
			&& $this->view->run()) {
			return true;
		}
		return false;
	}


	/**
	 * @param array<int, string> $batch
	 * @return array<int, array<string, mixed>>
	 * @throws BuddyRequestError
	 */
	private function mapMessages(array $batch): array {
		$results = [];
		foreach ($batch as $message) {
			$parsedMessage = json_decode($message, true);
			if (is_array($parsedMessage)) {
				$message = array_change_key_case($parsedMessage);
			} else {
				$message = [];
			}

			$results[] = $this->handleRow($message);
		}

		return $results;
	}

	/**
	 * @param array<string, string> $message
	 * @return array<string, bool|float|int|string>
	 * @throws BuddyRequestError
	 */
	private function handleRow(array $message): array {
		$row = [];
		foreach ($this->fields as $fieldName => $fieldType) {
			if (isset($message[$fieldName])) {
				$row[$fieldName] = $this->morphValuesByFieldType($message[$fieldName], $fieldType);
			} else {
				if (in_array(
					$fieldType, [Fields::TYPE_INT,
						Fields::TYPE_BIGINT,
						Fields::TYPE_TIMESTAMP,
						Fields::TYPE_BOOL,
						Fields::TYPE_FLOAT]
				)) {
					$row[$fieldName] = 0;
				} else {
					$row[$fieldName] = "''";
				}
			}
		}
		return $row;
	}

	/**
	 * @param array<int, array<string, mixed>> $batch
	 * @return bool
	 * @throws \Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError
	 */
	private function insertToBuffer(array $batch): bool {

		$fields = implode(', ', array_keys($batch[0]));

		$values = [];
		foreach ($batch as $row) {
			$values[] = '( ' . implode(', ', array_values($row)) . ' )';
		}
		$values = implode(",\n", $values);
		$sql = /** @lang ManticoreSearch */
			"REPLACE INTO $this->bufferTable ($fields) VALUES $values";
		$request = $this->client->sendRequest($sql);

		if ($request->hasError()) {
			Buddy::debug("Error inserting to buffer table $this->bufferTable. Reason: " . $request->getError());
			return false;
		}
		return true;
	}


}

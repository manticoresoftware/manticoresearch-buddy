<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

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
use Manticoresearch\Buddy\Core\Process\WorkerRunnerInterface;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Lib\BuddyRequestError;
use RdKafka\Conf;
use RdKafka\Exception;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;

class KafkaWorker implements WorkerRunnerInterface {
	use StringFunctionsTrait;

	private Client $client;
	private string $brokerList;
	/** @var array<string, string> */
	private array $customMapping;
	/** @var array<int, string> */
	private array $topicList;
	private string $consumerGroup;
	private string $bufferTable;
	private int $batchSize;
	private bool $consuming = true;
	private View $view;
	private Batch $batch;
	private KafkaConsumer $consumer;
	/** @var array<int> */
	private array $partitions = [];

	/**
	 * @param Client $client
	 * @param array{
	 *   full_name:string,
	 *   buffer_table:string,
	 *   destination_name:string,
	 *   custom_mapping: string,
	 *   query:string,
	 *   attrs:string } $instance
	 *
	 * @throws GenericError
	 */
	public function __construct(
		Client $client,
		array  $instance
	) {
		/** @var array{group:string, broker:string, topic:string, batch:string, partitions:array<int> } $attrs */
		$attrs = simdjson_decode($instance['attrs'], true);

		$this->client = $client;
		$this->consumerGroup = $attrs['group'];
		$this->brokerList = $attrs['broker'];
		$this->partitions = $attrs['partitions'];

		$decodedMapping = simdjson_decode($instance['custom_mapping'], true);
		if ($decodedMapping === false) {
			GenericError::throw(
				'Custom mapping decoding error: '.json_last_error_msg()
			);
		}
		/** @var array<string,string> $decodedMapping */
		$this->customMapping = $decodedMapping;
		$this->bufferTable = $instance['buffer_table'];
		$this->topicList = array_map(fn($item) => trim($item), explode(',', $attrs['topic']));
		$this->batchSize = (int)$attrs['batch'];
		$this->view = new View(
			$this->client,
			$this->bufferTable,
			$instance['destination_name'],
			"{$instance['query']} LIMIT {$this->batchSize}"
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
		$conf->set('auto.offset.reset', 'earliest');
		$conf->set('enable.partition.eof', 'true');

		if (empty($this->partitions)) {
			$conf->setRebalanceCb(
				function (KafkaConsumer $kafka, $err, ?array $partitions = null) {
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
		}

		return $conf;
	}

	/**
	 * @return void
	 * @throws \RdKafka\Exception
	 */
	public function init(): void {
		$this->batch = new Batch($this->batchSize);
		$this->batch->setCallback(
			function ($batch) {
				return $this->processBatch($batch);
			}
		);

		$conf = $this->initKafkaConfig();
		$this->consumer = new KafkaConsumer($conf);

		if (!empty($this->partitions)) {
			$topicPartitions = [];
			foreach ($this->topicList as $topic) {
				foreach ($this->partitions as $partition) {
					$topicPartitions[] = new TopicPartition($topic, $partition);
				}
			}
			$this->consumer->assign($topicPartitions);
		} else {
			$this->consumer->subscribe($this->topicList);
		}
	}


	/**
	 * @return void
	 * @throws Exception
	 */
	public function run(): void {
		$lastFullMessage = null;
		Buddy::debugvv('Worker: Start consuming');
		while ($this->consuming) {
			$message = $this->consumer->consume(1000);
			switch ($message->err) {
				case RD_KAFKA_RESP_ERR_NO_ERROR:
					$lastFullMessage = $message;
					if ($this->batch->add($message->payload)) {
						$this->consumer->commit($message);
					}
					break;
				case RD_KAFKA_RESP_ERR__PARTITION_EOF:
					break;
				case RD_KAFKA_RESP_ERR__TIMED_OUT:
					if ($this->batch->checkProcessingTimeout()
						&& $this->batch->process()
						&& $lastFullMessage !== null) {
						$this->consumer->commit($lastFullMessage);
					}
					break;
				default:
					throw new \Exception($message->errstr(), $message->err);
			}
		}
		Buddy::debugvv('Worker: End consuming');
	}

	public function stop(): void {
		$this->consuming = false;
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
			$parsedMessage = simdjson_decode($message, true);
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
			$inputKeyName = $fieldName;
			if (isset($this->customMapping[$fieldName])) {
				$inputKeyName = $this->customMapping[$fieldName];
			}
			if (isset($message[$inputKeyName])) {
				$row[$fieldName] = $this->morphValuesByFieldType($message[$inputKeyName], $fieldType);
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

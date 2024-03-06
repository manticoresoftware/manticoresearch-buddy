<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Workers;

use Manticoresearch\Buddy\Base\Plugin\Queue\Batch;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Fields;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use RdKafka\Exception;
use RdKafka\KafkaConsumer;

class KafkaWorker
{
	private Client $client;

	private string $brokerList;
	private array $topicList;
	private string $consumerGroup;

	private string $bufferTable;
	private array $fields;


	/**
	 */
	public function __construct(
		Client $client,
		string $brokerList,
		string $topicList,
		string $consumerGroup,
		string $bufferTable,
		array  $fields
	) {
		$this->client = $client;
		$this->consumerGroup = $consumerGroup;
		$this->brokerList = $brokerList;
		$this->bufferTable = $bufferTable;
		$this->fields = $fields;
		$this->topicList = array_map(fn($item) => trim($item), explode(',', $topicList));
	}

	public function setBatchSize() {
	}

	/**
	 * @throws Exception
	 */
	public function run() {
		Buddy::debugv('------->> Start consuming');

		$batch = new Batch(20);
		$batch->setCallback(
			function ($batch) {
				return $this->processBatch($batch);
			}
		);

		$conf = new \RdKafka\Conf();
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
						Buddy::debugv('-----> KafkaWorker worker -> Assign: ' . json_encode($partitions));
						$kafka->assign($partitions);
						break;

					case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
						Buddy::debugv('-----> KafkaWorker worker -> Revoke: ' . json_encode($partitions));
						$kafka->assign(null);
						break;

					default:
						throw new \Exception($err);
				}
			}
		);


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
					Buddy::debugv('-----> KafkaWorker worker -> No more messages; will wait for more ');
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

	public function processBatch(array $batch): bool {
		$failed = 0;
		foreach ($batch as $message) {
			if ($this->process($message)) {
				continue;
			}

			$failed++;
		}

		return $failed === 0;
	}

	private function process(string $message): bool {
		if ($this->insertMessageIntoBuffer($this->mapMessage($message))) {
			return true;
		}
		return false;
	}

	private function mapMessage(string $message): array {
		$message = array_change_key_case(json_decode($message, true));
		$values = [];
		foreach ($this->fields as $fieldName => $fieldType) {
			if (isset($message[$fieldName])) {
				$values[$fieldName] = $this->morphValuesByFieldType($message[$fieldName], $fieldType);
			} else {
				if (in_array(
					$fieldType, [Fields::TYPE_INT,
						Fields::TYPE_BIGINT,
						Fields::TYPE_TIMESTAMP,
						Fields::TYPE_BOOL,
						Fields::TYPE_FLOAT]
				)) {
					$values[$fieldName] = 0;
				} else {
					$values[$fieldName] = "''";
				}
			}
		}
		return $values;
	}

	private function insertMessageIntoBuffer(array $message): bool {
		$fields = implode(', ', array_keys($message));
		$values = implode(', ', array_values($message));
		$sql = "REPLACE INTO $this->bufferTable ($fields) VALUES ($values)";
		$request = $this->client->sendRequest($sql);

		if ($request->hasError()) {
			Buddy::debugv($sql);
			Buddy::debug($request->getError());
			return false;
		}
		return true;
	}

	/**
	 * TODO Duplicate from Replace plugin. Expose to core
	 */
	private function morphValuesByFieldType(mixed $fieldValue, string $fieldType): mixed {
		return match ($fieldType) {
			Fields::TYPE_INT, Fields::TYPE_BIGINT, Fields::TYPE_TIMESTAMP => (int)$fieldValue,
			Fields::TYPE_BOOL => (bool)$fieldValue,
			Fields::TYPE_FLOAT => (float)$fieldValue,
			Fields::TYPE_TEXT, Fields::TYPE_STRING, Fields::TYPE_JSON =>
				"'" . $this->escapeSting((is_array($fieldValue) ? json_encode($fieldValue) : $fieldValue)) . "'",
			Fields::TYPE_MVA, Fields::TYPE_MVA64, Fields::TYPE_FLOAT_VECTOR =>
				'(' . (is_array($fieldValue) ? implode(',', $fieldValue) : $fieldValue) . ')',
			default => $this->escapeSting($fieldValue)
		};
	}

	private function escapeSting(string $value): string {
		return str_replace("'", "\'", $value);
	}
}

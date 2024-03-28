<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka;

use Manticoresearch\Buddy\Base\Plugin\Queue\StringFunctionsTrait;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Fields;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use RdKafka\Exception;
use RdKafka\KafkaConsumer;

class KafkaWorker
{
	use StringFunctionsTrait;

	private Client $client;

	private string $name;
	private string $brokerList;
	private array $topicList;
	private string $consumerGroup;

	private string $bufferTable;
	private int $batchSize;

	private View $view;


	/**
	 * @throws GenericError
	 */
	public function __construct(
		Client $client,
		array  $instance
	) {

		$attrs = json_decode($instance['attrs'], true);

		$this->name = $instance['full_name'];
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
			$instance['query'],
			$this->batchSize
		);
		$this->getFields($this->client, $this->bufferTable);
	}


	private function initKafkaConfig() {
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
		return $conf;
	}

	/**
	 * @throws Exception
	 */
	public function run() {
		Buddy::debugv('------->> Start consuming ' . $this->name);

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
		if ($this->insertToBuffer($this->mapMessages($batch))
			&& $this->view->run()) {
			return true;
		}
		return false;
	}


	private function mapMessages(array $batch): array {
		$results = [];
		foreach ($batch as $message) {
			$message = array_change_key_case(json_decode($message, true));
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
			$results[] = $row;
		}

		return $results;
	}

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
			Buddy::debugv($sql);
			Buddy::debug($request->getError());
			return false;
		}
		return true;
	}


}

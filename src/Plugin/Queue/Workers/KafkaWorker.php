<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Workers;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use RdKafka\Exception;
use RdKafka\KafkaConsumer;

class KafkaWorker
{
	private Client $client;

	private string $brokerList;
	private array $topicList;
	private string $consumerGroup;

	/**
	 * @throws ManticoreSearchClientError
	 */
	public function __construct(Client $client, string $brokerList, string $topicList, string $consumerGroup) {
		$this->client = $client;
		$this->consumerGroup = $consumerGroup;
		$this->brokerList = $brokerList;

		$this->topicList = array_map(fn($item) => trim($item), explode(',', $topicList));
	}

	/**
	 * @throws Exception
	 */
	public function run() {
		Buddy::debugv("------->> Start consuming");
		$conf = new \RdKafka\Conf();
		$conf->set('group.id', $this->consumerGroup);
		$conf->set('metadata.broker.list', $this->brokerList);

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
						Buddy::debugv('KafkaWorker worker -> Assign: '.json_encode($partitions));
						$kafka->assign($partitions);
						break;

					case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
						Buddy::debugv('KafkaWorker worker -> Revoke: '.json_encode($partitions));
						$kafka->assign(null);
						break;

					default:
						throw new \Exception($err);
				}
			}
		);


		$consumer = new KafkaConsumer($conf);
		$consumer->subscribe($this->topicList);


		while (true) {
			$message = $consumer->consume(120 * 1000);
			switch ($message->err) {
				case RD_KAFKA_RESP_ERR_NO_ERROR:
					$this->insertMessageToBuffer($message->payload);
					$this->updateOffset($message->partition, $message->offset);
					break;
				case RD_KAFKA_RESP_ERR__PARTITION_EOF:
					Buddy::debugv('KafkaWorker worker -> No more messages; will wait for more ');
					break;
				case RD_KAFKA_RESP_ERR__TIMED_OUT:
					Buddy::debugv('KafkaWorker worker -> Consume timeout ');
					break;
				default:
					throw new \Exception($message->errstr(), $message->err);
			}
		}
	}

	private function insertMessageToBuffer(string $messages) {
		Buddy::debug($messages);
	}

	private function updateOffset($partition, $offset) {
		Buddy::debug("Partition $partition Offset $offset");
	}
}

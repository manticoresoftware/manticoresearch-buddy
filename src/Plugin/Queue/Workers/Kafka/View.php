<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;

class View
{
	const int BATCH = 200;
	private Client $client;

	private string $query;
	private string $destination;

	public function __construct(Client $client, string $destination, string $query) {
		$this->client = $client;
		$this->destination = $destination;
		$this->query = $query;

		if (stripos($query, ' limit ') !== false) {
			throw GenericError::create("Can't use query with limit");
		}
	}

	public function run(): bool {

		$errors = 0;
		while (true) {
			$batch = $this->read();
			if ($batch === []) {
				break;
			}

			if ($this->insert($batch)) {
				continue;
			}

			$errors++;
		}

		return $errors === 0;
	}

	private function read(): array {
		$sql = $this->query . ' LIMIT ' . self::BATCH;
		$readBuffer = $this->client->sendRequest($sql);

		if ($readBuffer->hasError()) {
			throw GenericError::create("Can't read from buffer. " . $readBuffer->getError());
		}
		$readBuffer = $readBuffer->getResult();
		return $readBuffer[0]['data'];
	}

	private function insert(array $batch): bool {

		Buddy::debugv(json_encode($batch));
//		$sql = "INSERT INTO $this->destination () VALUES ";
//		$request = $this->client->sendRequest($sql);
//
//		if ($request->hasError()) {
//			return false;
//		}
//
//		return true;
		return false;
	}

}

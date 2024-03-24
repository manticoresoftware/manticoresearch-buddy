<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka;

use Manticoresearch\Buddy\Base\Plugin\Queue\StringFunctionsTrait;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

class View
{
	use StringFunctionsTrait;

	private Client $client;

	private string $query;

	private string $buffer;
	private string $destination;

	private int $batchSize;

	public function __construct(Client $client, string $buffer, string $destination, string $query, int $batchSize) {
		$this->client = $client;
		$this->buffer = $buffer;
		$this->destination = $destination;
		$this->query = $query;
		$this->batchSize = $batchSize;

		$this->getFields($client, $destination);
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

			$batch = $this->prepareValues($batch);
			if ($this->insert($batch)) {
				continue;
			}
			$errors++;
		}

		return $errors === 0;
	}

	private function read(): array {
		$sql = "$this->query LIMIT $this->batchSize";
		$readBuffer = $this->client->sendRequest($sql);

		if ($readBuffer->hasError()) {
			throw GenericError::create("Can't read from buffer. " . $readBuffer->getError());
		}
		$readBuffer = $readBuffer->getResult();
		return $readBuffer[0]['data'];
	}

	private function prepareValues(array $batch): array {
		foreach ($batch as $k => $row) {
			foreach ($row as $name => $value) {
				$batch[$k][$name] = $this->morphValuesByFieldType($value, $this->getFieldType($name));
			}
		}
		return $batch;
	}

	private function insert(array $batch): bool {

		$values = [];
		$keys = implode(', ', array_keys($batch[0]));

		$ids = [];
		foreach ($batch as $row) {
			$ids[$row['id']] = 1;
			$values[] = '(' . implode(',', array_values($row)) . ')';
		}
		$values = implode(',', $values);
		unset($batch);

		$sql = "INSERT INTO $this->destination ($keys) VALUES $values";
		$request = $this->client->sendRequest($sql);

		if ($request->hasError()) {
			return false;
		}

		$ids = implode(',', array_keys($ids));
		$sql = "DELETE FROM $this->buffer WHERE id in($ids)";

		$request = $this->client->sendRequest($sql);

		if ($request->hasError()) {
			return false;
		}

		return true;
	}

}

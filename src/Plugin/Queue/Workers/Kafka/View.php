<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka;

use Manticoresearch\Buddy\Base\Plugin\Queue\StringFunctionsTrait;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;

class View
{
	use StringFunctionsTrait;

	private Client $client;

	private string $query;

	private string $buffer;
	private string $destination;

	public function __construct(Client $client, string $buffer, string $destination, string $query) {
		$this->client = $client;
		$this->buffer = $buffer;
		$this->destination = $destination;
		$this->query = $query;
		$this->getFields($client, $destination);
	}

	/**
	 * @throws GenericError
	 */
	public function run(): bool {

		if ($this->insert($this->prepareValues($this->read()))) {
			return true;
		}

		return false;
	}

	private function read(): array {
		$sql = "$this->query";
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

		$fields = array_keys($batch[0]);

		$needAppendId = false;
		if (!in_array('id', array_map('strtolower', $fields))) {
			$needAppendId = true;
			array_unshift($fields, 'id');
		}

		Buddy::debugv("=============3=====>".json_encode($fields));
		$keys = implode(', ', $fields);

		$insertEntities = [];
		foreach ($batch as $row) {
			$rowValues = array_values($row);
			if ($needAppendId) {
				array_unshift($rowValues, '0');
			}
			$insertEntities[] = '(' . implode(',', $rowValues) . ')';
		}
		$values = implode(',', $insertEntities);
		unset($batch, $insertEntities);

		$sql = "REPLACE INTO $this->destination ($keys) VALUES $values";
		$request = $this->client->sendRequest($sql);

		if ($request->hasError()) {
			Buddy::debugv('----> Error during inserting to destination table. ' . $request->getError());
		}
		$sql = "TRUNCATE TABLE $this->buffer";

		$request = $this->client->sendRequest($sql);

		if ($request->hasError()) {
			Buddy::debugv('----> Error truncating buffer table table. ' . $request->getError());
			return false;
		}

		return true;
	}

}

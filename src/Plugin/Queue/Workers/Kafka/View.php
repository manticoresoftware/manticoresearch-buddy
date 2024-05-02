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
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Lib\BuddyRequestError;

class View {
	use StringFunctionsTrait;

	private Client $client;

	private string $query;

	private string $buffer;
	private string $destination;

	/**
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 */
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

	/**
	 * @return array<int, array<string, string>>
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 */
	private function read(): array {
		$sql = "$this->query";
		$readBuffer = $this->client->sendRequest($sql);

		if ($readBuffer->hasError()) {
			throw GenericError::create("Can't read from buffer. " . $readBuffer->getError());
		}
		$readBuffer = $readBuffer->getResult();

		$result = [];
		if (is_array($readBuffer[0])) {
			$result = $readBuffer[0]['data'];
		}
		return $result;
	}

	/**
	 * @param array<int, array<string, string>> $batch
	 * @return array<int, array<string, mixed>>
	 * @throws BuddyRequestError
	 */
	private function prepareValues(array $batch): array {
		foreach ($batch as $k => $row) {
			foreach ($row as $name => $value) {
				$batch[$k][$name] = $this->morphValuesByFieldType($value, $this->getFieldType($name));
			}
		}
		return $batch;
	}

	/**
	 * @param array<int, array<string, mixed>> $batch
	 * @return bool
	 * @throws ManticoreSearchClientError
	 */
	private function insert(array $batch): bool {

		$fields = array_keys($batch[0]);

		$needAppendId = false;
		if (!in_array('id', array_map('strtolower', $fields))) {
			$needAppendId = true;
			array_unshift($fields, 'id');
		}

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

		$sql /** @lang Manticore */ = "REPLACE INTO $this->destination ($keys) VALUES $values";
		$request = $this->client->sendRequest($sql);

		if ($request->hasError()) {
			Buddy::debug('Error occurred during inserting to destination table. Reason:' . $request->getError());
		}
		$sql = "TRUNCATE TABLE $this->buffer";

		$request = $this->client->sendRequest($sql);

		if ($request->hasError()) {
			Buddy::debug('Error truncating buffer table. Reason:' . $request->getError());
			return false;
		}

		return true;
	}

}

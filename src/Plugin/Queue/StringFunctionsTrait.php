<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Fields;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Lib\BuddyRequestError;

trait StringFunctionsTrait {

	/**
	 * @var array<string, string>
	 */
	protected array $fields = [];


	/**
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 */
	protected function getFields(Client $client, string $tableName): void {
		$desc = $client->sendRequest('DESC ' . $tableName);

		if ($desc->hasError()) {
			Buddy::debug(
				"Can't describe table " . $tableName . '. Reason: '
				. $desc->getError()
			);
			throw GenericError::create(
				"Can't describe table " . $tableName . '. Reason: '
				. $desc->getError()
			);
		}

		$this->fields = [];
		if (!is_array($desc->getResult()[0])) {
			return;
		}

		/**
		 * @var array{Field:string, Type:string} $field
		 */
		foreach ($desc->getResult()[0]['data'] as $field) {
			$this->fields[$field['Field']] = $field['Type'];
		}
	}

	/**
	 * @param string $field
	 *
	 * @return string
	 */
	protected function getFieldType(string $field): string {
		return $this->fields[$field];
	}

	/**
	 * @param mixed $fieldValue
	 * @param string $fieldType
	 *
	 * @return string|int|bool|float
	 * @throws BuddyRequestError
	 */
	protected function morphValuesByFieldType(
		mixed $fieldValue,
		string $fieldType
	): string|int|bool|float {
		$fieldValue = $this->mixedToString($fieldValue);

		if ($fieldValue === false) {
			throw BuddyRequestError::create("Error or JSON parsing for attr $fieldType");
		}

		return match ($fieldType) {
			Fields::TYPE_INT, Fields::TYPE_BIGINT => (int)$fieldValue,
			Fields::TYPE_TIMESTAMP => is_numeric($fieldValue)
				? (int)$fieldValue
				: "'" . self::escapeSting($fieldValue) . "'",
			Fields::TYPE_BOOL => (bool)$fieldValue,
			Fields::TYPE_FLOAT => (float)$fieldValue,
			Fields::TYPE_TEXT, Fields::TYPE_STRING, Fields::TYPE_JSON =>
				"'" . $this->escapeSting($fieldValue) . "'",
			Fields::TYPE_MVA, Fields::TYPE_MVA64, Fields::TYPE_FLOAT_VECTOR =>
				'(' . $this->prepareMvaField($fieldValue) . ')',
			default => $this->escapeSting($fieldValue)
		};
	}

	/**
	 * @param string $fieldValue
	 *
	 * @return string
	 */
	private function prepareMvaField(string $fieldValue): string {
		if (isset($fieldValue[0]) && $fieldValue[0] === '[') {
			$fieldValue = json_decode($fieldValue, true);
		}


		if (is_array($fieldValue)) {
			return implode(',', $fieldValue);
		}

		settype($fieldValue, 'string');
		return $fieldValue;
	}

	/**
	 * @param string $value
	 *
	 * @return string
	 */
	protected function escapeSting(string $value): string {
		return str_replace("'", "\'", $value);
	}


	/**
	 * Just for phpstan
	 *
	 * @param mixed $input
	 *
	 * @return false|string
	 */
	private function mixedToString(mixed $input): false|string {
		if (is_array($input)) {
			return json_encode($input);
		}

		settype($input, 'string');
		return $input;
	}
}

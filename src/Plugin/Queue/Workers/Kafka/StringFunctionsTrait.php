<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Fields;
use Manticoresearch\Buddy\Core\Tool\Buddy;

trait StringFunctionsTrait
{

	protected array $fields = [];


	protected function getFields(Client $client, string $tableName) {
		$desc = $client->sendRequest('DESC ' . $tableName);

		if ($desc->hasError()) {
			Buddy::debug("Can't describe table " . $tableName . '. Reason: ' . $desc->getError());
			throw GenericError::create("Can't describe table " . $tableName . '. Reason: ' . $desc->getError());
		}

		$this->fields = [];
		foreach ($desc->getResult()[0]['data'] as $field) {
			$this->fields[$field['Field']] = $field['Type'];
		}
	}

	protected function getFieldType($field) {
		return $this->fields[$field];
	}

	/**
	 * TODO Duplicate from Replace plugin. Expose to core
	 */
	protected function morphValuesByFieldType(mixed $fieldValue, string $fieldType): mixed {
		return match ($fieldType) {
			Fields::TYPE_INT, Fields::TYPE_BIGINT, Fields::TYPE_TIMESTAMP => (int)$fieldValue,
			Fields::TYPE_BOOL => (bool)$fieldValue,
			Fields::TYPE_FLOAT => (float)$fieldValue,
			Fields::TYPE_TEXT, Fields::TYPE_STRING, Fields::TYPE_JSON =>
				"'" . $this->escapeSting((is_array($fieldValue) ? json_encode($fieldValue) : $fieldValue)) . "'",
			Fields::TYPE_MVA, Fields::TYPE_MVA64, Fields::TYPE_FLOAT_VECTOR =>
				'(' . $this->prepareMvaField($fieldValue) . ')',
			default => $this->escapeSting($fieldValue)
		};
	}

	private function prepareMvaField($fieldValue) {
		if (isset($fieldValue[0]) && $fieldValue[0] === '[') {
			$fieldValue = json_decode($fieldValue, true);
		}
		return is_array($fieldValue) ? implode(',', $fieldValue) : $fieldValue;
	}

	protected function escapeSting(string $value): string {
		return str_replace("'", "\'", $value);
	}
}

<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\Datalim;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use RuntimeException;

/**
 * Handles JSON fields and their sub fields
 */
class FieldCapsHandlerHelper {

	/** @var HTTPClient $manticoreClient */
	protected HTTPClient $manticoreClient;

	/** @var string $table */
	protected string $table;

	/** @var array<string,mixed> $fieldValues */
	protected array $fieldValues;

	/** @var array<string,array<string>> $subFields */
	protected array $subFields;

	/**
	 * @param HTTPClient $client
	 * $return void
	 */
	public function setManticoreClient(HTTPClient $client): void {
		$this->manticoreClient = $client;
	}

	/**
	 * @param string $table
	 * $return void
	 */
	public function setTable(string $table): void {
		$this->table = $table;
	}

	/**
	 * Checking if a working table has been set
	 *
	 * @return void
	 * @throws RuntimeException
	 */
	protected function checkTable(): void {
		if (!isset($this->table)) {
			throw new \Exception('Working table is not set');
		}
	}

	/**
	 * Getting the values of all JSON fields in the table
	 *
	 * @return void
	 */
	protected function setFieldValues(): void {
		$this->checkTable();
		$this->fieldValues = [];
		/** @var array{0:array{data:array<array<string,mixed>>}} */
		$queryResult = $this->manticoreClient
			->sendRequest("SELECT * FROM {$this->table} LIMIT 1")
			->getResult();
		if (!$queryResult[0]['data']) {
			return;
		}
		foreach ($queryResult[0]['data'][0] as $field => $val) {
			$this->fieldValues[$field] = $val;
		}
	}

	/**
	 * @param int $v
	 * @return string
	 */
	protected static function getIntType(int $v) {
		return $v > Datalim::MySqlMaxInt->value ? 'long' : 'integer';
	}

	/**
	 * @param string $v
	 * @return string
	 */
	protected static function checkIfDateOrKeyword(string $v) {
		$dateRegex = '/^\d{4}\-\d{2}\-\d{2}(T\d{2}$|((T|\s)\d{2}:\d{2}(:\d{2}((\.\d{3})?(\+\d{2}:\d{2}Z?)?)?)?)?$)/';
		return preg_match($dateRegex, $v) ? 'date' : 'keyword';
	}

	/**
	 * Detecting the data types of JSON sub fields
	 *
	 * @param array<mixed> $jsonObj
	 * @param string $curPropName
	 * @param array<string,string> $fieldsInfo
	 * @return string
	 */
	protected static function detectFieldTypes(array $jsonObj, string $curPropName, array &$fieldsInfo): string {
		foreach ($jsonObj as $k => $v) {
			$propName = $curPropName . '.' . (string)$k;
			$fieldType = match (true) {
				is_int($v) => self::getIntType($v),
				is_float($v) => 'float',
				is_string($v) => self::checkIfDateOrKeyword($v),
				is_bool($v) => 'boolean',
				is_array($v) => array_is_list($v) ? 'object' : self::detectFieldTypes($v, $propName, $fieldsInfo),
				default => 'object',
			};
			$fieldsInfo[$propName] = $fieldType;
		}

		return 'object';
	}

	/**
	 * Getting all sub fields of a given JSON field through SHOW TABLE INDEXES
	 *
	 * @param string $fieldName
	 * @return array<string,string>
	 */
	protected function getSubFields(string $fieldName): array {
		if (isset($this->subFields)) {
			// Extra check to handle possibly incorrect field detection with SHOW TABLE INDEXES
			return array_key_exists($fieldName, $this->subFields) ? $this->subFields[$fieldName] : [];
		}
		$this->checkTable();
		// Flushing indexes info in case it's unavailable yet
		$this->manticoreClient->sendRequest("FLUSH RAMCHUNK {$this->table}");
		/** @var array{0:array{data:array<int,array{Name:string,Type:string,Enabled:int,Percent:int}>}} */
		$queryResult = $this->manticoreClient
			->sendRequest("SHOW TABLE {$this->table} INDEXES")
			->getResult();
		if (!$queryResult[0]['data']) {
			return [];
		}
		$this->extractSubFields($queryResult[0]['data']);

		// Extra check to handle possibly incorrect field detection with SHOW TABLE INDEXES
		return array_key_exists($fieldName, $this->subFields) ? $this->subFields[$fieldName] : [];
	}

	/**
	 * @param array<string,array<mixed>> $fieldJson
	 * @param string $subFieldName
	 * @param mixed $subFieldValue
	 * @return void
	 */
	protected function updateFieldJson(array &$fieldJson, string $subFieldName, mixed $subFieldValue): void {
		$curJson = &$fieldJson;
		$subProps = explode('.', $subFieldName);
		$propCount = sizeof($subProps) - 1;
		foreach ($subProps as $i => $prop) {
			if (!isset($curJson[$prop])) {
				/** @var array<string,array<mixed>> $curJson */
				$curJson[$prop] = $i < $propCount ? [] : $subFieldValue;
			}
			/** @var array<string,array<mixed>> $curJson */
			$curJson = &$curJson[$prop];
		}
	}

	/**
	 * Getting the values of all previously extracted sub field names and adding them to a single JSON object
	 *
	 * @param array<string,string> $subFields
	 * @param string $parentFieldName
	 * @return array<mixed>
	 */
	protected function buildFieldJson(array $subFields, string $parentFieldName): array {
		if (!$subFields) {
			return [];
		}
		$fieldJson = [];
		$unprocessedFields = [];
		do {
			$selectExpr = implode(',', $subFields);
			$whereExpr = $unprocessedFields ? "WHERE {$unprocessedFields[0]} IS NOT NULL" : '';
			// We use a tmporary workaround with the 'sql' endpoint here to get correct data representation
			// until the issue is fixed in daemon
			/** @var array{hits:array{hits:array<int,array{_source:array<string,mixed>}>}} */
			$queryResult = $this->manticoreClient
				->sendRequest("SELECT $selectExpr FROM {$this->table} $whereExpr LIMIT 1", 'sql', false, true)
				->getResult();
			// We treat NULL json fields as empty objects
			if ($unprocessedFields && !$queryResult['hits']['hits']) {
				self::updateFieldJson($fieldJson, $unprocessedFields[0], []);
				array_shift($unprocessedFields);
				continue;
			}
			$unprocessedFields = [];
			foreach ($queryResult['hits']['hits'][0]['_source'] as $subFieldName => $subFieldValue) {
				if ($subFieldValue === null) {
					$unprocessedFields[] = $subFieldName;
					continue;
				}
				self::updateFieldJson($fieldJson, $subFieldName, $subFieldValue);
			}
			$subFields = $unprocessedFields;
		} while ($unprocessedFields);

		return $fieldJson[$parentFieldName];
	}

	/**
	 * Getting sub field names from indexes info and convert their format from `f['a']['b']` to `f.a.b`
	 * for further processing
	 *
	 * @param array<int,array{Name:string,Type:string,Enabled:int,Percent:int}> $indexesInfo
	 * @return void
	 */
	protected function extractSubFields(array $indexesInfo): void {
		$allFieldNames = array_column($indexesInfo, 'Name');
		foreach ($indexesInfo as $fieldInfo) {
			$indexName = $fieldInfo['Name'];
			// Ignoring disabled or non-json fields
			if (!$fieldInfo['Enabled'] || !str_ends_with($indexName, "']")) {
				continue;
			}
			// We don't need the names of intermediate JSON fields for further processing, just the 'leaf' fields
			// E.g., if we have `f['a']['b]`, we can ignore `f['a']`
			foreach ($allFieldNames as $name) {
				if ($name !== $indexName && str_starts_with($name, $indexName)) {
					continue;
				}
			}
			$fieldNameLen = (int)strpos($indexName, "['");
			$fieldName = substr($indexName, 0, $fieldNameLen);
			$subField = $fieldName . '.' . str_replace("']['", '.', substr($indexName, $fieldNameLen + 2, -2));
			$this->subFields[$fieldName][] = $subField;
		}
	}

	/**
	 * Getting the names and data types of JSON sub fields
	 *
	 * @param string $fieldName
	 * @param bool $isIndexedField
	 * @return array<string,string>
	 */
	public function getJsonSubFieldsInfo(string $fieldName, bool $isIndexedField): array {
		$fieldsInfo = [];
		if ($isIndexedField) {
			// Using `SHOW TABLE INDEXES` command to get necessary info about indexed sub fields from its output
			$subFields = $this->getSubFields($fieldName);
			$jsonObj = $this->buildFieldJson($subFields, $fieldName);
		} else {
			// Getting JSON field values available from the table's first document
			if (!isset($this->fieldValues)) {
				$this->setFieldValues();
				if (!$this->fieldValues) {
					return [];
				}
			}
			/** @var string|null $json */
			$json = $this->fieldValues[$fieldName];
			$jsonObj = $json ? (array)simdjson_decode($json, true) : [];
		}
		self::detectFieldTypes($jsonObj, $fieldName, $fieldsInfo);
		ksort($fieldsInfo);

		return $fieldsInfo;
	}
}

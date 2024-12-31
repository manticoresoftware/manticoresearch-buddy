<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

/**
 *  Parses search request from Kibana
 */
class TableFieldInfo {

	const REQUEST_TABLE_INFO_ENDPOINT = '/_field_caps';

	/** @var array<string,array<string,array<string,array{aggregatable:bool}>>> $fieldPerTableInfo */
	protected $fieldPerTableInfo = [];
	/** @var array<string,array<string,array{aggregatable:bool}>> $fieldInfo */
	protected array $fieldInfo = [];
	/** @var array<string> $tables */
	protected array $tables = [];

	/**
	 * @param string $requestTable
	 * @param Client $manticoreClient
	 */
	public function __construct(protected string $requestTable, protected Client $manticoreClient) {
		$this->load();
	}

	/**
	 * @return array<string>
	 */
	public function getTables(): array {
		return $this->tables;
	}

	/**
	 * @return array<string,array<string,array{aggregatable:bool}>>
	 */
	public function get(): array {
		return $this->fieldInfo;
	}

	/**
	 * @param string $table
	 * @return array<string>
	 */
	public function getFieldNamesByTable(string $table): array {
		if (sizeof($this->tables) < 2) {
			return array_keys($this->fieldInfo);
		}
		if (!array_key_exists($table, $this->fieldPerTableInfo)) {
			$this->load($table);
		}
		return array_keys($this->fieldPerTableInfo[$table]);
	}

	/**
	 * @return array<string>
	 */
	public function getFieldNames(): array {
		return array_keys($this->fieldInfo);
	}

	/**
	 * @return void
	 */
	protected function load(string $table = ''): void {
		$requestTable = $table ?: $this->requestTable;
		/** @var array{
		 * fields:array<string,array<string,array{aggregatable:bool}>>,
		 * indices:array<string>
		 * } $requestTableInfo
		 */
		$requestTableInfo = $this->manticoreClient->sendRequest(
			'{"fields":"*"}',
			$requestTable . static::REQUEST_TABLE_INFO_ENDPOINT,
			true
		)->getResult();

		if ($table) {
			$this->fieldPerTableInfo[$table] = $requestTableInfo['fields'];
		} else {
			$this->fieldInfo = $requestTableInfo['fields'];
			$this->tables = $requestTableInfo['indices'];
		}
	}
}

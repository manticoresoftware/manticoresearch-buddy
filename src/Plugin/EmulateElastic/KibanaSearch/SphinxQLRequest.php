<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch;

/**
 *  Handles the creation of SphinxQL search requests sent to Manticore
 */
final class SphinxQLRequest {

	const COUNT_FIELD_EXPR = 'count(*)';
	const RESPONSE_SIZE_LIMIT = 1000;

	/** @var array<array{0:string,1:string}> $fields */
	private array $fields = [];
	/** @var array<string> $groupFields */
	private array $groupFields = [];
	/** @var array<string> $whereExprs */
	private array $whereExprs = [];
	/** @var array<string> $orderFields */
	private array $orderFields = [];
	/** @var array<string,mixed> $options */
	private array $options = [];
	/** @var string $table */
	private string $table = '';

	/**
	 * @param string $table
	 * @return void
	 */
	public function init(string $table): void {
		$this->table = $table;
		$this->resetFields();
	}

	/**
	 * @return string
	 */
	public function getTable(): string {
		return $this->table;
	}

	/**
	 * @return string
	 */
	public function buildQuery(): string {
		if (!$this->table) {
			return '';
		}
		$this->checkForCountField();

		$query = '';
		// Building necessary query clauses according to the request data
		if ($this->whereExprs) {
			$whereClause = implode(' AND ', $this->whereExprs);
			$query .= " WHERE $whereClause";
		}
		if ($this->groupFields) {
			$groupClause = implode(',', $this->groupFields);
			$query .= " GROUP BY $groupClause";
		}
		if ($this->orderFields) {
			$orderClause = implode(
				',',
				array_map(
					fn ($field) => "$field {$this->orderFields[$field]}",
					array_keys($this->orderFields)
				)
			);
			$query .= " ORDER BY $orderClause";
		}

		// We need to override Manticore's default limit here
		$query .= ' LIMIT 0,' . self::RESPONSE_SIZE_LIMIT;

		if ($this->options) {
			$optionClause = implode(
				',',
				array_map(
					fn ($field) => "$field=" .
						(is_string($this->options[$field]) ? "'{$this->options[$field]}'" : $this->options[$field]),
					array_keys($this->options)
				)
			);
			$query .= " OPTION $optionClause";
		}
		$fields = [];
		foreach ($this->fields as [$field, $alias]) {
			$aliasExpr = $alias ? " AS `$alias`" : '';
			$fields[] = $field . $aliasExpr;
		}
		$fieldClause = implode(',', $fields);
		$query = "SELECT $fieldClause FROM `{$this->table}`" . $query;

		return $query;
	}

	/**
	 * @param string $field
	 * @param string $alias
	 * @return void
	 */
	public function addField(string $field, string $alias = ''): void {
		$fieldInfo = [$field, $alias];
		if (in_array($fieldInfo, $this->fields)) {
			return;
		}
		$this->fields[] = $fieldInfo;
	}

	/**
	 * @param string $expr
	 * @return void
	 */
	public function addWhereExpr(string $expr): void {
		$this->whereExprs[] = $expr;
	}

	/**
	 * @param string $field
	 * @return void
	 */
	public function addGroupField(string $field): void {
		if (in_array($field, $this->groupFields)) {
			return;
		}
		$this->groupFields[] = $field;
	}

	/**
	 * @return array<string>
	 */
	public function getGroupFields(): array {
		return $this->groupFields;
	}

	/**
	 * @param string $field
	 * @param string $order
	 * @return void
	 */
	public function addOrderField(string $field, string $order): void {
		if (array_key_exists($field, $this->orderFields)) {
			return;
		}
		$this->orderFields[$field] = $order;
	}

	/**
	 * @param string $name
	 * @param mixed $val
	 * @return void
	 */
	public function addOption(string $name, mixed $val): void {
		$this->options[$name] = $val;
	}

	/**
	 * @return string
	 */
	public function getCountField(): string {
		return self::COUNT_FIELD_EXPR;
	}

	/**
	 * @return void
	 */
	private function resetFields(): void {
		$this->fields = $this->groupFields = $this->whereExprs = $this->orderFields = $this->options = [];
	}

	/**
	 * The Count field must always exist in the query
	 *
	 * @return void
	 */
	private function checkForCountField(): void {
		if (in_array(
			self::COUNT_FIELD_EXPR,
			array_map(
				fn ($fieldInfo) => $fieldInfo[0],
				$this->fields
			)
		)) {
			return;
		}
		$this->addField(self::COUNT_FIELD_EXPR);
	}
}

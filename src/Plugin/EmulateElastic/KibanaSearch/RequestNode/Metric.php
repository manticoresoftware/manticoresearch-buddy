<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode;

/**
 *  Metric node of Kibana search request
 */
final class Metric extends ExprNode {

	/**
	 * @param string $key
	 * @param string $argField
	 * @param string $name
	 * @param string $func
	 */
	public function __construct(
		protected string $key,
		protected string $argField,
		private string $name = '',
		private string $func = ''
	) {
	}

	/**
	 * @param string $nodeName
	 * @return void
	 */
	public function setName(string $nodeName): void {
		$this->name = $this->func = $nodeName;
	}

	/**
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * @return string
	 */
	public function getFunc(): string {
		return $this->func;
	}

	/**
	 * @return string
	 */
	public function getArgField(): string {
		return $this->argField;
	}

	/**
	 * @param array<string,mixed> $responseNode
	 * @param array<string, mixed> $dataRow
	 * @param string $nextNodeKey
	 * @return array<string|int>|false
	 */
	public function fillInResponse(array &$responseNode, array $dataRow, string $nextNodeKey): array|false {
		/** @var array{value:string} $subNode */
		$subNode = &$responseNode[$this->key];
		if (array_key_exists($this->key, $dataRow)) {
			$subNode['value'] = $dataRow[$this->key];
		} else {
			$dataField = $this->fieldAlias ?: $this->getField();
			if (array_key_exists($dataField, $dataRow)) {
				$subNode['value'] = $dataRow[$dataField];
			}
		}
		return $nextNodeKey === '' ? [$nextNodeKey] : [];
	}

	/**
	 * @ return void
	 */
	protected function makeFieldExpr(): void {
		$this->fieldExpr = "{$this->func}({$this->argField})";
	}
}

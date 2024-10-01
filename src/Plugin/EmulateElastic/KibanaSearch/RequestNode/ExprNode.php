<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Interfaces\AliasedNodeInterface;

/**
 *  Node of Kibana search request representing a Kibana expression
 */
abstract class ExprNode extends AggNode implements AliasedNodeInterface {

	/* @var string $argField */
	protected string $argField = '';
	/* @var string $fieldExpr */
	protected string $fieldExpr = '';
	/** @var string $fieldAlias */
	protected string $fieldAlias = '';

	/**
	 * @return string $alias
	 */
	public function getFieldAlias(): string {
		return $this->fieldAlias;
	}

	/**
	 * @param string $alias
	 * @return void
	 */
	public function setFieldAlias(string $alias): void {
		$this->fieldAlias = $alias;
	}

	/**
	 * @return string
	 */
	public function getField(): string {
		if (!$this->fieldExpr) {
			$this->makeFieldExpr();
		}
		return $this->fieldExpr;
	}

	/**
	 * @return string
	 */
	public function getArgField(): string {
		return $this->argField;
	}

	/**
	 * @return void
	 */
	public function fillInRequest(): void {
		if (!$this->fieldExpr) {
			$this->makeFieldExpr();
		}
		$this->request->addField($this->fieldExpr, $this->fieldAlias);
	}

	/**
	 * @ return void
	 */
	abstract protected function makeFieldExpr(): void;
}

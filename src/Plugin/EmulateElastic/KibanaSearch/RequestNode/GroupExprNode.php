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
 *  Node of Kibana search request
 */
abstract class GroupExprNode extends ExprNode {

	/* @var string $groupField */
	protected string $groupField = '';

	/**
	 * @return void
	 */
	public function fillInRequest(): void {
		parent::fillInRequest();
		$this->groupField = $this->fieldAlias ?: $this->field;
		$this->request->addGroupField($this->groupField);
	}
}

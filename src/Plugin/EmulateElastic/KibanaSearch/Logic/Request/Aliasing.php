<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Interfaces\RequestLogicInterface;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Interfaces\AliasedNodeInterface;

/**
 *  Generates aliases for node fields making sure they don't coincide with existing fields
 */
class Aliasing implements RequestLogicInterface {

	const ALIAS_PREFIX = 'al';

	/** @var int $aliasCount */
	protected int $aliasCount = 0;

	/**
	 * @param array<AliasedNodeInterface> $aliasedNodes
	 * @param array<string> $fieldNames
	 */
	public function __construct(private array $aliasedNodes, private array $fieldNames) {
	}

	/**
	 * @return static
	 */
	public function apply(): static {
		foreach ($this->aliasedNodes as $node) {
			if ($node->getFieldAlias()) {
				continue;
			}
			$alias = $this->generateAlias();
			$node->setFieldAlias($alias);
		}

		return $this;
	}

	/**
	 * @return string
	 */
	public function generateAlias(): string {
		$alias = static::ALIAS_PREFIX . ++$this->aliasCount;
		while (in_array($alias, $this->fieldNames)) {
			$alias .= '_';
		}
		return $alias;
	}
}

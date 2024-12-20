<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode;

// @phpcs:ignore
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Helpers\FilterExpression\FilterExpression;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Interfaces\FilterNodeInterface;

/**
 *  Filter node of Kibana search request
 */
final class QueryFilter extends BaseNode implements FilterNodeInterface {

	/** @var string $whereExpr */
	private string $whereExpr = '';

	/**
	 * @param string $key
	 * @param array{bool:array{filter:array<mixed>,should:array<mixed>,must:array<mixed>,must_not:array<mixed>}} $filter
	 * @param FilterExpression $filterExpression
	 */
	public function __construct(
		protected string $key,
		private array $filter,
		private FilterExpression $filterExpression
	) {
		$this->unique();
		$this->filterExpression->setFilterNode($this);
	}

	/**
	 * @return bool
	 */
	public function isDirect(): bool {
		return true;
	}

	/**
	 * @return array<mixed>
	 */
	public function getFilter(): array {
		return $this->filter;
	}

	/**
	 * @return void
	 */
	public function fillInRequest(): void {
		if ($this->isDisabled) {
			return;
		}
		if (!$this->whereExpr) {
			$this->whereExpr = $this->filterExpression->build();
		}
		$this->request->addOption('not_terms_only_allowed', 1);
		$this->request->addWhereExpr($this->whereExpr);
	}

	/**
	 * Getting duplicate filter data Kibana 7.6.0 for some reason sends and remove it
	 *
	 * @return void
	 */
	private function unique(): void {
		foreach (['filter', 'should', 'must', 'must_not'] as $filterType) {
			/** @var array<array<mixed>> $filterNodes */
			$filterNodes = &$this->filter['bool'][$filterType];
			foreach ($this->getDuplicateNodeKeys($filterNodes) as $k) {
				unset($filterNodes[$k]);
			}
		}
	}

	/**
	 * @param array<array<mixed>> $filterNodes
	 * @return array<int|string>
	 */
	private function getDuplicateNodeKeys(array $filterNodes): array {
		$subFilterType = null;
		$duplicateNodeKeys = [];
		foreach (array_keys($filterNodes) as $k) {
			$isEvenKey = (int)$k % 2 === 0;
			if ($isEvenKey) {
				$subFilterType = array_key_first($filterNodes[$k]);
			} elseif (array_key_first($filterNodes[$k]) === $subFilterType) {
				$duplicateNodeKeys[] = $k;
			}
		}

		return $duplicateNodeKeys;
	}

	/**
	 * @return bool
	 */
	public function hasFilterData(): bool {
		foreach ($this->filter['bool'] as $key => $subFilter) {
			if ($key !== 'filter' && $subFilter) {
				return true;
			}
			/** @var array<array<mixed>> $subFilter */
			foreach ($subFilter as $dataNode) {
				if ($this->hasSubFilterData($dataNode)) {
					return true;
				}
			}
		}
		return 	false;
	}

	/**
	 * @param array<mixed> $dataNode
	 * @return bool
	 */
	private function hasSubFilterData(array $dataNode): bool {
		foreach ($dataNode as $val) {
			if ($val) {
				return true;
			}
		}
		return true;
	}
}

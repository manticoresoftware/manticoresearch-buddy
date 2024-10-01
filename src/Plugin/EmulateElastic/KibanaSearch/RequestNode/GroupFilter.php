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
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Interfaces\AliasedNodeInterface;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Interfaces\FilterNodeInterface;

/**
 *  Filter node of Kibana search request used for filtering Kibana aggregations
 */
class GroupFilter extends AggNode implements AliasedNodeInterface, FilterNodeInterface {

	/** @var bool $hasQueryFilter */
	protected bool $hasQueryFilter = false;
	/** @var string $countField */
	protected string $countField = '';
	/** @var string $groupField */
	protected string $groupField = '';
	/** @var string $fieldAlias */
	protected string $fieldAlias = '';
	/** @var string $ifField */
	protected string $ifField = '';
	/** @var string $whereExpr */
	private string $whereExpr = '';
	/** @var bool $isConcurrent */
	private bool $isConcurrent = false;
	/** @var bool $isDirect */
	private bool $isDirect = true;

	/**
	 * @param string $key
	 * @param array<mixed> $filter
	 * @param string $name
	 * @param FilterExpression $filterExpression
	 */
	public function __construct(
		protected string $key,
		protected array $filter,
		protected string $name,
		private FilterExpression $filterExpression
	) {
		$this->filterExpression->setFilterNode($this);
	}

	/** @return void */
	public function disable(): void {
		$this->isDisabled = true;
	}

	/**
	 * @return bool
	 */
	public function isDisabled(): bool {
		return $this->isDisabled;
	}

	/**
	 * @return array<mixed>
	 */
	public function getFilter(): array {
		return $this->filter;
	}

	/**
	 * @return array<string>
	 */
	public function getFilterFields(): array {
		return $this->filterExpression->getFilterFields();
	}

	/**
	 * @param string $nodeName
	 * @return void
	 */
	public function setName(string $nodeName): void {
		$this->name = $nodeName;
	}

	/**
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * @return bool
	 */
	public function prepare(): bool {
		if ($this->isDisabled) {
			return false;
		}
		if (!$this->whereExpr) {
			$this->buildWhereExpr();
		}
		return true;
	}

	/**
	 * @param string $cond
	 * @param array<mixed> $filter
	 * @param bool $inNotFilter
	 * @return string
	 */
	public function buildExprFromFilter(string $cond, array $filter, bool $inNotFilter): string {
		if ($cond && $filter && $inNotFilter) {
			return '';
		}
		return '';
	}

	/**
	 * @return string
	 */
	public function buildWhereExpr(): string {
		$this->whereExpr = $this->filterExpression->build();
		return $this->whereExpr;
	}

	/**
	 * @return void
	 */
	public function fillInRequest(): void {
		if (!$this->whereExpr) {
			$this->whereExpr = $this->filterExpression->build();
		}
		$this->request->addOption('not_terms_only_allowed', 1);
		if ($this->isDirect) {
			$this->request->addWhereExpr($this->whereExpr);
		} else {
			// If a filter cannot be added directly in the request, we apply Manticore's IF expression instead
			// to use the result later while post-processing Manticore response
			$this->ifField = "if($this->whereExpr,1,0)";
			$this->request->addField($this->ifField, $this->fieldAlias);
			$groupField = $this->fieldAlias ?: $this->ifField;
			$this->request->addGroupField($groupField);
			$this->request->addOrderField($groupField, 'asc');
			$this->groupField = $groupField;
		}
	}

	/**
	 * @param bool $hasQueryFilter
	 */
	public function setQueryFilter(bool $hasQueryFilter): void {
		$this->hasQueryFilter = $hasQueryFilter;
	}

	/**
	 * @param array<string,mixed> $responseNode
	 * @param array<string, mixed> $dataRow
	 * @param string $nextNodeKey
	 * @return array<string|int>|false
	 */
	public function fillInResponse(array &$responseNode, array $dataRow, string $nextNodeKey): array|false {
		if (!$this->countField) {
			$this->countField = $this->request->getCountField();
		}
		if (!array_key_exists($this->key, $responseNode)) {
			$responseNode[$this->key] = [];
		}
		/** @var array<mixed> $subNode */
		$subNode = &$responseNode[$this->key];
		if (!array_key_exists('buckets', $subNode)) {
			$subNode['buckets'] = [];
		}
		$buckets = &$subNode['buckets'];
		$count = array_key_exists($this->countField, $dataRow) ? $dataRow[$this->countField] : 0;

		$isWrapperFilter = !array_key_exists($this->fieldAlias, $dataRow);
		$dataField = $this->fieldAlias ?: $this->field;
		$isMatchingFilter = $isWrapperFilter || $dataRow[$dataField];
		if (!array_key_exists($this->name, $buckets)) {
			// Create a new bucket for the filter data
			$buckets[$this->name] = [
				'doc_count' => $isMatchingFilter ? $count : 0,
			];
			// Create an empty object for the bucket's sub-data if it doesn't exist yet
			if ($nextNodeKey !== $this->key && !array_key_exists($nextNodeKey, $buckets[$this->name])) {
				$buckets[$this->name][$nextNodeKey] = [
					'buckets' => [],
				];
			}
		} elseif ($isMatchingFilter) {
			$buckets[$this->name]['doc_count'] += $count;
		}

		// We return an empty result if a filter is not matched in the current node sub-tree
		return $isMatchingFilter ? [$this->key, 'buckets', $this->name] : [];
	}

	/** @return string */
	public function getField(): string {
		return $this->ifField;
	}

	/** @return string */
	public function getGroupField(): string {
		return $this->groupField;
	}

	/**
	 * @return bool
	 */
	public function hasGroupField(): bool {
		return $this->groupField !== '';
	}

	/** @return void */
	public function setGroupField(): void {
		$this->groupField = $this->fieldAlias ?: $this->ifField;
	}

	/**
	 * Determines if the filter can be applied in the request directly, via WHERE,
	 * or a workaround with the IF expression must be used
	 *
	 * @return void
	 */
	public function setAsIndirect(): void {
		$this->isDirect = false;
	}

	/**
	 * @return void
	 */
	public function setAsConcurrent(): void {
		$this->isConcurrent = true;
	}

	/**
	 * @return bool
	 */
	public function isConcurrent(): bool {
		return $this->isConcurrent;
	}

	/**
	 * @return bool
	 */
	public function isDirect(): bool {
		return $this->isDirect;
	}

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
}

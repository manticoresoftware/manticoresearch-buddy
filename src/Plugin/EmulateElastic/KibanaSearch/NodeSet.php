<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\AggNode;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\BaseNode;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\ExprNode;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\GroupFilter;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Interfaces\AliasedNodeInterface;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Metric;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\QueryFilter;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Term;

/**
 *  Extracts subsets of Kibana request nodes and their fields by given criteria
 */
class NodeSet {

	/** @var array<string> $fields */
	private array $fields;
	/** @var array<string> $groupFields */
	private array $groupFields;
	/** @var array<string> $metricFields */
	private array $metricFields;
	/** @var array<string,array<int,BaseNode>> $nodesByClass */
	private array $nodesByClass = [];
	/** @var array<string,array<int,BaseNode>> $nodesByInterface */
	private array $nodesByInterface = [];

	/**
	 * @param array<BaseNode> $nodes
	 */
	public function __construct(private array $nodes) {
	}

	/**
	 * @return array<BaseNode>
	 */
	public function getNodes(): array {
		return $this->nodes;
	}

	/**
	 * @param string $interface
	 * @return array<BaseNode>
	 * @throws \Exception
	 */
	public function getNodesByInterface(string $interface): array {
		if (!interface_exists($interface)) {
			throw new \Exception("Non-existing interface $interface is passed");
		}
		return $this->nodesByInterface[$interface] ?? $this->extractNodesByInterface($interface);
	}

	/**
	 * @param string $cls
	 * @return array<BaseNode>
	 * @throws \Exception
	 */
	public function getNodesByClass(string $cls): array {
		if (!class_exists($cls)) {
			throw new \Exception("Non-existing class $cls is passed");
		}
		return $this->nodesByClass[$cls] ?? $this->extractNodesByClass($cls);
	}

	/**
	 * @return array<int,string>
	 */
	public function getFields(): array {
		$this->fields = [];
		foreach ($this->nodes as $i => $node) {
			if (!is_a($node, AggNode::class)) {
				continue;
			}
			$this->fields[$i] = $node instanceof AliasedNodeInterface
				? $node->getFieldAlias() ?: $node->getField()
				: $node->getField();
		}

		return $this->fields;
	}

	/**
	 * @return array<int,string>
	 */
	public function getGroupFields(): array {
		$this->groupFields = [];
		foreach ($this->nodes as $i => $node) {
			if (!is_a($node, AggNode::class) || is_a($node, Metric::class) || ($this->isGroupNodeInvalid($node))) {
				continue;
			}
			$this->groupFields[$i] = $node instanceof AliasedNodeInterface
				? ($node->getFieldAlias() ?: $node->getField())
				: $node->getField();
		}

		return $this->groupFields;
	}

	/**
	 * @return array<int,string>
	 */
	public function getMetricFields(): array {
		$this->metricFields = [];
		foreach ($this->nodes as $i => $node) {
			if (!is_a($node, Metric::class)) {
				continue;
			}
			$this->metricFields[$i] = $node->getFieldAlias() ?: $node->getField();
		}

		return $this->metricFields;
	}

	/**
	 * @return int
	 */
	public function getFieldCount(): int {
		return isset($this->fields) ? sizeof($this->fields) : 0;
	}

	/**
	 * @return int
	 */
	public function getGroupFieldCount(): int {
		return isset($this->groupFields) ? sizeof($this->groupFields) : 0;
	}

	/**
	 * @return array<int,array<string>>
	 */
	public function getArgFields(): array {
		$argFields = [];
		foreach ($this->nodes as $i => $node) {
			if (is_a($node, QueryFilter::class)) {
				continue;
			}
			$argFields[$i] = $this->extractNodeArgFields($node);
		}

		return $argFields;
	}

	/**
	 * @param BaseNode $node
	 * @return array<string>
	 * @throws \Exception
	 */
	protected static function extractNodeArgFields(BaseNode $node): array {
		return match (true) {
			is_a($node, ExprNode::class) => [$node->getArgField()],
			is_a($node, Term::class) => [$node->getField()],
			is_a($node, GroupFilter::class) => $node->getFilterFields(),
			default => throw new \Exception('Unknown node type is passed'),
		};
	}

	/**
	 * @param string $interface
	 * @return array<BaseNode>
	 */
	private function extractNodesByInterface(string $interface): array {
		$this->nodesByInterface[$interface] = array_filter(
			$this->nodes,
			fn ($node) => $node instanceof $interface
		);
		return $this->nodesByInterface[$interface];
	}

	/**
	 * @param string $cls
	 * @return array<BaseNode>
	 */
	private function extractNodesByClass(string $cls): array {
		$this->nodesByClass[$cls] = array_filter(
			$this->nodes,
			fn ($node) => is_a($node, $cls)
		);
		return $this->nodesByClass[$cls];
	}

	/**
	 * @param AggNode $node
	 * @return bool
	 */
	private function isGroupNodeInvalid(AggNode $node): bool {
		return is_a($node, GroupFilter::class) && ($node->isDirect() || $node->isDisabled());
	}
}

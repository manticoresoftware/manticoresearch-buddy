<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode;

// @phpcs:ignore;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Helpers\FilterExpression\Factory
	as FilterExpressionFactory;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Helpers\TimeZoneExpression;

/**
 * Creates node objects from Kibana request data
 */
class Factory {

	const NODE_MANDATORY_FIELDS = [
		'terms' => ['field', 'size', 'order'],
		'histogram' => ['field', 'interval'],
		'date_histogram' => ['field', 'time_zone'],
		'range' => ['field', 'ranges'],
		'date_range' => ['field', 'ranges', 'time_zone'],
	];

	/**
	 * @param TimeZoneExpression $timeZoneExpression
	 * @param FilterExpressionFactory $filterExpressionFactory
	 */
	public function __construct(
		protected TimeZoneExpression $timeZoneExpression,
		protected FilterExpressionFactory $filterExpressionFactory
	) {
	}

	/**
	 * @param array<mixed> $nodeData
	 * @param array<string> $nodeFields
	 * @param string $nodeSignature
	 */
	protected function checkNodeData(
		array $nodeData,
		array $nodeFields,
		string $nodeSignature
	): void {
		foreach ($nodeFields as $field) {
			if (!array_key_exists($field, $nodeData)) {
				throw new \Exception("Field '$field' missing in node '$nodeSignature'");
			}
		}
	}

	/**
	 * @param array{
	 * field:string,
	 * size:int,
	 * order:array<string,string>,
	 * min_doc_count?:int,
	 * extended_bounds?:array{0:int,1:int},
	 * interval:int,
	 * calendar_interval?:string,
	 * fixed_interval?:string,
	 * time_zone:string,
	 * ranges:array<int,array{from?:string|float,to?:string|float}>,
	 * bool:array{filter:array<mixed>,should:array<mixed>,must:array<mixed>,must_not:array<mixed>}
	 * } $nodeData
	 * @param string $nodeName
	 * @param string $nodeKey
	 * @return BaseNode
	 */
	public function createNode(
		array $nodeData,
		string $nodeName,
		string $nodeKey,
	): BaseNode {
		$nodeSignature = "$nodeKey: $nodeName";
		if (array_key_exists($nodeName, static::NODE_MANDATORY_FIELDS)) {
			$this->checkNodeData($nodeData, static::NODE_MANDATORY_FIELDS[$nodeName], $nodeSignature);
		}
		switch ($nodeName) {
			case 'terms':
				/** @var array<string,string> $order */
				$order = $nodeData['order'];
				$orderType = (string)array_key_first($order);
				$orderVal = $order[$orderType];

				return new Term($nodeKey, $orderType, $orderVal, $nodeData['field'], $nodeData['size']);
			case 'histogram':
				$isExtendable = array_key_exists('min_doc_count', $nodeData) ? !$nodeData['min_doc_count'] : false;
				$bounds = array_key_exists('extended_bounds', $nodeData)
					? array_values($nodeData['extended_bounds'])
					: [false, false];
				[$intervalFrom, $intervalTo] = $bounds;

				return new Histogram(
					$nodeKey,
					$nodeData['field'],
					$nodeData['interval'],
					$isExtendable,
					$intervalFrom,
					$intervalTo,
				);
			case 'date_histogram':
				if (array_key_exists('calendar_interval', $nodeData)) {
					$interval = $nodeData['calendar_interval'];
				} elseif (array_key_exists('fixed_interval', $nodeData)) {
					$interval = $nodeData['fixed_interval'];
				} else {
					throw new \Exception("No 'interval' is provided in node '$nodeSignature'");
				}

				return new DateHistogram(
					$nodeKey,
					$interval,
					$nodeData['field'],
					$nodeData['time_zone'],
					$this->timeZoneExpression
				);
			case 'range':
				/** @var array<int,array{from:float,to:float}> $ranges */
				$ranges = array_map(
					fn ($range) => [
						'from' => $range['from'] ?? 0,
						'to' => $range['to'] ?? 0,
					],
					$nodeData['ranges']
				);
				return new Range($nodeKey, $nodeData['field'], $ranges);
			case 'date_range':
				/** @var array<int,array{from:string,to:string}> $ranges */
				$ranges = array_map(
					fn ($range) => [
						'from' => $range['from'] ?? '',
						'to' => $range['to'] ?? '',
					],
					$nodeData['ranges']
				);
				return new DateRange(
					$nodeKey,
					$nodeData['field'],
					$ranges,
					$nodeData['time_zone'],
					$this->timeZoneExpression
				);
			case 'query':
				$filterExpression = $this->filterExpressionFactory->create();
				return new QueryFilter($nodeKey, $nodeData, $filterExpression);
			case 'max':
			case 'min':
			case 'sum':
			case 'avg':
				return new Metric($nodeKey, $nodeData['field']);
			default:
				$filterExpression = $this->filterExpressionFactory->create();
				return new GroupFilter($nodeKey, $nodeData['bool'], $nodeName, $filterExpression);
		}
	}
}

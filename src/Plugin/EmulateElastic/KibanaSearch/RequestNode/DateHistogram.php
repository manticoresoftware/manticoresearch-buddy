<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Helpers\TimeZoneExpression;

/**
 *  Date histogram node of Kibana search request
 */
final class DateHistogram extends GroupExprNode {

	/**
	 * @param string $key
	 * @param string $interval
	 * @param string $argField
	 * @param string $timezone
	 * @param TimeZoneExpression $timeZoneExpression
	 */
	public function __construct(
		protected string $key,
		protected string $interval,
		protected string $argField,
		private string $timezone,
		private TimeZoneExpression $timeZoneExpression
	) {
	}

	/**
	 * @return void
	 */
	public function fillInRequest(): void {
		parent::fillInRequest();
		$this->request->addOrderField($this->groupField, 'asc');
	}

	/**
	 * @param array<string,mixed> $responseNode
	 * @param array<string, mixed> $dataRow
	 * @param string $nextNodeKey
	 * @return array<string|int>|false
	 */
	public function fillInResponse(array &$responseNode, array $dataRow, string $nextNodeKey): array|false {
		$this->makeResponseBucketsIfNotExist($responseNode);
		if (!array_key_exists($this->groupField, $dataRow)) {
			return [];
		}
		/** @var int $key */
		$key = $dataRow[$this->groupField];
		$offsetExpr = $this->timeZoneExpression->makeTimezoneExpr($this->timezone);
		$keyAsString = substr(date('Y-m-d\TH:i:s\.u', $key), 0, -3) . $offsetExpr;
		// Converting timestamp to Kibana's format with microseconds
		$key *= 1000;
		$buckets = &$responseNode[$this->key]['buckets'];
		$docCount = $dataRow[$this->countField];
		$bucketKey = $this->findBucket($buckets, 'key', $key);
		if ($bucketKey === -1) {
			$bucketKey = sizeof($buckets);
			$buckets[$bucketKey] = [
				'key' => $key,
				'key_as_string' => $keyAsString,
				'doc_count' => $docCount,
			];
			if ($nextNodeKey) {
				$buckets[$bucketKey][$nextNodeKey] = [];
			}
		} else {
			$buckets[$bucketKey]['doc_count'] += $docCount;
		}

		return [$this->key, 'buckets', (string)$bucketKey];
	}

	/**
	 * @return void
	 */
	protected function makeFieldExpr(): void {
		$this->fieldExpr = "DATE_HISTOGRAM({$this->argField}, {calendar_interval='{$this->interval}'})";
	}
}

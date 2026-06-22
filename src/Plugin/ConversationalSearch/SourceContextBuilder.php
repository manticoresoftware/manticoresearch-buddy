<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalSearch;

use JsonException;
use Manticoresearch\Buddy\Core\Tool\Buddy;

final class SourceContextBuilder {
	private const string SOURCE_ID_FIELD = 'id';

	/**
	 * @param array<int, array<string, mixed>> $searchResults
	 *
	 * @throws JsonException
	 */
	public function build(array $searchResults, string $contentFields, int $maxDocumentLength): string {
		if ($searchResults === []) {
			return '';
		}

		$fields = $this->parseContentFields($contentFields);
		$this->warnAboutMissingFields($searchResults[0], $fields);

		$sources = [];
		foreach ($searchResults as $source) {
			$sources[] = $this->cropSource($this->buildLlmSource($source, $fields), $maxDocumentLength);
		}

		return (string)json_encode($sources, JSON_THROW_ON_ERROR);
	}

	/**
	 * @return array<int, string>
	 */
	private function parseContentFields(string $contentFields): array {
		$fields = array_map('trim', explode(',', $contentFields));

		return array_values(array_filter($fields, static fn(string $field): bool => $field !== ''));
	}

	/**
	 * @param array<string, mixed> $firstSource
	 * @param array<int, string> $fields
	 */
	private function warnAboutMissingFields(array $firstSource, array $fields): void {
		$missingFields = array_diff($fields, array_keys($firstSource));
		if ($missingFields === []) {
			return;
		}

		Buddy::warning('Content fields not found in search results: ' . implode(', ', $missingFields));
	}

	/**
	 * LLM context includes only the source id and non-empty string fields used by the selected vector field.
	 * Other scalar metadata is intentionally excluded from prompts.
	 *
	 * @param array<string, mixed> $source
	 * @param array<int, string> $fields
	 *
	 * @return array<string, string>
	 */
	private function buildLlmSource(array $source, array $fields): array {
		$filtered = [];
		$id = $source[self::SOURCE_ID_FIELD] ?? null;
		if (is_scalar($id)) {
			$filtered[self::SOURCE_ID_FIELD] = (string)$id;
		}

		foreach ($fields as $field) {
			$value = $source[$field] ?? null;
			if (!is_string($value) || trim($value) === '') {
				continue;
			}

			$filtered[$field] = $value;
		}

		return $filtered;
	}

	/**
	 * @param array<string, string> $source
	 *
	 * @return array<string, string>
	 * @throws JsonException
	 */
	private function cropSource(array $source, int $maxLength): array {
		if ($maxLength === 0) {
			return $source;
		}

		// Keep reducing the longest string field until the JSON object fits the per-source budget.
		while ($this->jsonLength($source) > $maxLength) {
			$stringFields = $this->stringFieldLengths($source);
			if ($stringFields === []) {
				return $source;
			}

			$field = array_key_first($stringFields);
			$source[$field] = $this->cropLongestField(
				(string)$source[$field],
				$stringFields[$field],
				$this->nextFieldLength($stringFields),
				$this->jsonLength($source) - $maxLength
			);
		}

		return $source;
	}

	private function cropLongestField(string $value, int $length, int $nextLength, int $overflow): string {
		$reduction = min($overflow, max(1, $length - $nextLength));
		$targetLength = $length - $reduction;
		if ($targetLength <= 3) {
			return '';
		}

		return substr($value, 0, $targetLength - 3) . '...';
	}

	/**
	 * @param array<string, string> $source
	 *
	 * @return array<string, int>
	 */
	private function stringFieldLengths(array $source): array {
		$lengths = [];
		foreach ($source as $field => $value) {
			if ($field === self::SOURCE_ID_FIELD || $value === '') {
				continue;
			}

			$lengths[$field] = strlen($value);
		}
		arsort($lengths);

		return $lengths;
	}

	/**
	 * @param array<string, int> $stringFields
	 */
	private function nextFieldLength(array $stringFields): int {
		$lengths = array_values($stringFields);
		return $lengths[1] ?? 0;
	}

	/**
	 * @param array<string, string> $source
	 *
	 * @throws JsonException
	 */
	private function jsonLength(array $source): int {
		return strlen((string)json_encode($source, JSON_THROW_ON_ERROR));
	}
}

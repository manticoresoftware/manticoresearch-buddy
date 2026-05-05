<?php declare(strict_types=1);

/*
  Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalSearch;

final readonly class ConversationMessage {
	public function __construct(
		public string $role,
		public string $message,
		public string $intent,
		public string $searchQuery,
		public string $excludeQuery,
		public string $excludedIds
	) {
	}

	public static function fromStored(
		string $role,
		string $message,
		string $intent,
		string $searchQuery,
		string $excludeQuery,
		string $excludedIds
	): self {
		return new self(
			$role,
			$message,
			$intent,
			$searchQuery,
			$excludeQuery,
			self::normalizeExcludedIds($excludedIds)
		);
	}

	public static function user(
		string $message,
		string $intent,
		string $searchQuery = '',
		string $excludeQuery = '',
		string $excludedIds = ''
	): self {
		return new self('user', $message, $intent, $searchQuery, $excludeQuery, $excludedIds);
	}

	/**
	 * @param array<int, string> $excludedIds
	 * @throws \JsonException
	 */
	public static function userWithExcludedIds(
		string $message,
		string $intent,
		string $searchQuery,
		string $excludeQuery,
		array $excludedIds
	): self {
		return self::user(
			$message,
			$intent,
			$searchQuery,
			$excludeQuery,
			json_encode($excludedIds, JSON_THROW_ON_ERROR)
		);
	}

	public static function assistant(string $message, string $intent): self {
		return new self('assistant', $message, $intent, '', '', '');
	}

	private static function normalizeExcludedIds(string $excludedIds): string {
		$excludedIds = trim($excludedIds);
		return strtoupper($excludedIds) === 'NULL' ? '' : $excludedIds;
	}
}

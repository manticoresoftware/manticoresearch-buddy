<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation;

final class ConversationRoute {

	public const string ANSWER_FROM_HISTORY = 'ANSWER_FROM_HISTORY';
	public const string SEARCH = 'SEARCH';
	public const string DIRECT_SEARCH = 'DIRECT_SEARCH';
	public const string DERIVE_THEN_SEARCH = 'DERIVE_THEN_SEARCH';
	public const string REJECT = 'REJECT';

	public function __construct(
		public string $route,
		public string $searchQuery,
		public string $excludeQuery,
		public string $reason,
		public string $deriveTask = ''
	) {
	}
}

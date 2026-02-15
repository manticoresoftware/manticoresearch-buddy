<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

final class ConversationRequest {

	public function __construct(
		public string $query,
		public string $table,
		public string $modelUuid,
		public string $contentFields,
		public string $conversationUuid = ''
	) {
	}

	public function withConversationUuid(string $conversationUuid): self {
		$clone = clone $this;
		$clone->conversationUuid = $conversationUuid;
		return $clone;
	}
}


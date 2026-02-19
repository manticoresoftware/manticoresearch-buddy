<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

final class SearchContext {

	/**
	 * @param string $intent
	 * @param ConversationRequest $request
	 * @param string $conversationHistory
	 * @param array{id:string, uuid:string, name:string,llm_provider:string,
	 *   llm_model:string,style_prompt:string,settings:array{ temperature?: string,
	 *   max_tokens?: string, k_results?: string, similarity_threshold?: string,
	 *   max_document_length?: string},created_at:string,updated_at:string} $model
	 */
	public function __construct(
		public string $intent,
		public ConversationRequest $request,
		public string $conversationHistory,
		public array $model
	) {
	}

	public function withIntent(string $intent): self {
		$clone = clone $this;
		$clone->intent = $intent;
		return $clone;
	}
}


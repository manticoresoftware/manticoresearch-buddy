<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalSearch;

final class SearchContext {

	/**
	 * @param ConversationRequest $request
	 * @param ConversationHistory $history
	 * @param array{
	 *   id:string,
	 *   uuid:string,
	 *   name:string,
	 *   model:string,
	 *   style_prompt:string,
	 *   settings:array<string, mixed>,
	 *   created_at:string,
	 *   updated_at:string
	 * } $model
	 * @param ConversationRoute $route
	 * @param VectorFieldInfo $vectorFieldInfo
	 */
	public function __construct(
		public ConversationRequest $request,
		public ConversationHistory $history,
		public array $model,
		public ConversationRoute $route,
		public VectorFieldInfo $vectorFieldInfo
	) {
	}
}

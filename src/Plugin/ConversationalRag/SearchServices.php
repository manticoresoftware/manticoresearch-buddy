<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class SearchServices {

	public function __construct(
		public ConversationManager $conversationManager,
		public LLMProviderManager $providerManager,
		public SearchEngine $searchEngine,
		public Client $client
	) {
	}
}


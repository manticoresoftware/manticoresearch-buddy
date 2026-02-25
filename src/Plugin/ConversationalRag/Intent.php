<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

final class Intent {
	public const REJECTION = 'REJECTION';
	public const ALTERNATIVES = 'ALTERNATIVES';
	public const TOPIC_CHANGE = 'TOPIC_CHANGE';
	public const INTEREST = 'INTEREST';
	public const NEW_SEARCH = 'NEW_SEARCH';
	public const CONTENT_QUESTION = 'CONTENT_QUESTION';
	public const NEW_QUESTION = 'NEW_QUESTION';
	public const CLARIFICATION = 'CLARIFICATION';
	public const UNCLEAR = 'UNCLEAR';
}

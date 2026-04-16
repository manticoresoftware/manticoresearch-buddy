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

	public const string FOLLOW_UP = 'FOLLOW_UP';
	public const string REFINE = 'REFINE';
	public const string EXPAND = 'EXPAND';
	public const string REJECT = 'REJECT';
	public const string NEW = 'NEW';
	public const string UNCLEAR = 'UNCLEAR';
}

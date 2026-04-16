<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

final readonly class TableSchema {
	/**
	 * @param array<int, string> $vectorFields
	 */
	public function __construct(
		public string $vectorField,
		public array $vectorFields,
		public string $contentFields
	) {
	}
}

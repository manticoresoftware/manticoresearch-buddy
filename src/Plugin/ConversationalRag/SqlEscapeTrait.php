<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

/**
 * SQL escaping utilities for ConversationalRag plugin
 */
trait SqlEscapeTrait {
	/**
	 * Escape string values for SQL queries using backslash escaping for ManticoreSearch
	 *
	 * @param string $value
	 * @return string
	 */
	protected function sqlEscape(string $value): string {
		return str_replace("'", "\\'", $value);
	}

	/**
	 * Quote and escape a string value for SQL queries
	 *
	 * @param string $value
	 * @return string
	 */
	protected function quote(string $value): string {
		return "'" . $this->sqlEscape($value) . "'";
	}
}

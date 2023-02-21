<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base;

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Lib\TableFormatter;

/**
 * This is the parent class to format Manticore client responses as tables
 */
abstract class FormattableClientQueryExecutor extends ClientQueryExecutor {
	/** @var TableFormatter $tableFormatter */
	protected TableFormatter $tableFormatter;

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return ['manticoreClient', 'tableFormatter'];
	}

	/**
	 *
	 * @param TableFormatter $formatter
	 * $return TableFormatter
	 */
	public function setTableFormatter(TableFormatter $formatter): TableFormatter {
		$this->tableFormatter = $formatter;
		return $this->tableFormatter;
	}
}

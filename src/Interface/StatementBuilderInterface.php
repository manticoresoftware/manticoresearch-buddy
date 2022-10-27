<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Interface;

interface StatementBuilderInterface {
	/**
	 * @param string $stmtName
	 * @param array<string> $stmtData
	 * @return string
	 */
	public function build(string $stmtName, array $stmtData): string;
}
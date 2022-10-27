<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Interface\StatementBuilderInterface;
use RuntimeException;

class StatementBuilder implements StatementBuilderInterface {

	const BUILD_MAP = [
		'CREATE' => ['name', 'cols', 'colTypes'],
	];

	/**
	 * @param string $stmtName
	 * @param array<string> $stmtData
	 * @return string
	 * @throws RuntimeException
	 */
	public function build($stmtName, array $stmtData): string {
		if (!array_key_exists($stmtName, self::BUILD_MAP)) {
			throw new RuntimeException("Unknown statement $stmtName");
		}
		$missingFields = array_diff(self::BUILD_MAP[$stmtName], array_keys($stmtData));
		if (!empty($missingFields)) {
			$missingFields = implode(',', $missingFields);
			throw new RuntimeException("Build fields $missingFields missing");
		}

		$buildArgs = [];
		foreach (self::BUILD_MAP[$stmtName] as $k) {
			$buildArgs[] = $stmtData[$k];
		}

		$methodName = 'build' . ucfirst(strtolower($stmtName)) . 'Stmt';
		return static::$methodName(...$buildArgs);
	}

	/**
	 * @param string $name
	 * @param array<string> $cols
	 * @param array<string> $colTypes
	 * @return string
	 */
	protected static function buildCreateStmt(string $name, array $cols, array $colTypes): string {
		$colExpr = implode(
			',',
			array_map(
				function ($a, $b) {
					return "$a $b";
				},
				$cols,
				$colTypes
			)
		);
		$repls = ['%NAME%' => $name, '%COL_EXPR%' => $colExpr];
		return strtr('CREATE TABLE IF NOT EXISTS %NAME% (%COL_EXPR%)', $repls);
	}

}

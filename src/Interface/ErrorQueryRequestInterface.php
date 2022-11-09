<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Interface;

use Manticoresearch\Buddy\Enum\MntEndpoint;

interface ErrorQueryRequestInterface extends CommandRequestInterface {

	/**
	 * @return void
	 */
	public function generateCorrectionStatements(): void;

	/**
	 * @return array<StatementInterface>
	 */
	public function getCorrectionStatements(): array;

	/**
	 * @return string
	 */
	public function getOrigMsg(): string;

	/**
	 * @return MntEndpoint
	 */
	public function getEndpoint(): MntEndpoint;

}

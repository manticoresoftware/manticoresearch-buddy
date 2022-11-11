<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Interface;

use Closure;
use Manticoresearch\Buddy\Enum\Action;

interface StatementInterface {
	/**
	 * @param string $body
	 * @param mixed $postprocessorInit
	 * @param ?Action $action
	 * @return StatementInterface
	 */
	public static function create(
		string $body,
		mixed $postprocessorInit = null,
		?Action $action = null
	): StatementInterface;

	/**
	 * @return string
	 */
	public function getBody(): string;

	/**
	 * @param string $body
	 * @return void
	 */
	public function setBody(string $body): void;

	/**
	 * @return bool
	 */
	public function hasPostprocessor(): bool;

	/**
	 * @return Closure|null
	 */
	public function getPostprocessor(): Closure|null;

	/**
	 * @param Closure $processor
	 * @return void
	 */
	public function setPostprocessor(Closure $processor): void;

}

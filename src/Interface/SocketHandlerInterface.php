<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Interface;

interface SocketHandlerInterface {
	/**
	 * @return bool
	 */
	public function hasMsg(): bool;
	/**
	 * @return array{type:string,message:string,reqest_type:string}|false
	 */
	public function readMsg(): array|false;
	/**
	 * @param array{type:string,message:string} $msgData
	 * @return void
	 */
	public function writeMsg(array $msgData): void;

	/**
	 * @return string
	 */
	public function read(): string;

	/**
	 * @param string $response
	 * @return void
	 */
	public function write(string $response): void;
}

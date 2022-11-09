<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Trait;

use \RuntimeException;

trait BuddyResponseTrait {

	/**
	 * @param string $message
	 * @param string $error
	 * @param ?class-string<RuntimeException> $ex
	 * @return string
	 */
	public static function buildResponse(
		string $message = '',
		string $error = '',
		?string $ex = null
	): string {
		if (!isset($ex)) {
			$ex = RuntimeException::class;
		}
		$msgData = [
			'type' => 'http response',
			'message' => $message,
			'error' => $error,
		];
		$body = json_encode($msgData);
		if ($body === false) {
			throw new $ex('JSON data encode error');
		}
		$body_len = strlen($body);
		$msg = "HTTP/1.1 200\r\nServer: buddy\r\nContent-Type: application/json; charset=UTF-8\r\n";
		$msg .= "Content-Length: $body_len\r\n\r\n" . $body;

		return $msg;
	}

}

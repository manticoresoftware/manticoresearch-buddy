<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Exception;

use Manticoresearch\Buddy\Interface\CustomErrorInterface;
use RuntimeException;

final class SocketError implements CustomErrorInterface {

	/**
	 * @param string|null $message
	 * @return void
	 * @throws RuntimeException
	 */
	public function throw(string $message = null): void {
		if ($message !== null) {
			$message .= ': ';
		}
		$message .= socket_strerror(socket_last_error());
		throw new RuntimeException($message);
	}
}
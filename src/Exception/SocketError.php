<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Exception;

use RuntimeException;
use Throwable;

final class SocketError extends RuntimeException {

	public function __construct(string $message = null, int $code = 0, ?Throwable $previous = null) {
		if (isset($message)) {
			$message .= ': ';
		}
		$message .= socket_strerror(socket_last_error());
		parent::__construct($message, $code, $previous);
	}

}

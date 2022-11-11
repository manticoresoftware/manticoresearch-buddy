<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Network;

use Throwable;

final class Response {
	/**
	 * @param string $data
	 * @return void
	 */
	public function __construct(protected string $data = '') {
	}

	/**
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->data === '';
	}

	/**
	 * @see static::fromStringAndError()
	 * @param string $message
	 * @return static
	 */
	public static function fromString(string $message): static {
		return static::fromStringAndError($message);
	}

	/**
	 * @see static::fromStringAndError()
	 * @param Throwable $error
	 * @return static
	 */
	public static function fromError(Throwable $error): static {
		return static::fromStringAndError('', $error);
	}

	/**
	 * @return static
	 */
	public static function none(): static {
		return new static;
	}

	/**
	 * @param string $message
	 * @param ?Throwable $error
	 * @return static
	 */
	public static function fromStringAndError(string $message = '', ?Throwable $error = null): static {
		$payload = [
			'type' => 'http response',
			'message' => $message,
			'error' => $error?->getMessage(),
		];
		return new static(
			json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE)
		);
	}

	/**
	 * This magic helps us to keep things simple :)
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->data;
	}
}

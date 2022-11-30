<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Exception\ManticoreResponseError ;
use Throwable;

class ManticoreResponse {

	/**
	 * @var array<string,mixed> $data
	 */
	protected array $data;

	/**
	 * @var array<string,mixed> $columns
	 */
	protected array $columns;

	/**
	 * @var ?string $error
	 */
	protected ?string $error;

	/**
	 * @param ?string $body
	 * @return void
	 */
	public function __construct(
		protected ?string $body = null
	) {
		$this->parse();
	}

	/**
	 * @return ?string
	 */
	public function getError(): string|null {
		return $this->error;
	}

	/**
	 * @return string
	 */
	public function getBody(): string {
		return (string)$this->body;
	}

	/**
	 * @return bool
	 */
	public function hasError(): bool {
		return isset($this->error) ? true : false;
	}

	/**
	 * @param callable $processor
	 * @param array<mixed> $args
	 * @return void
	 * @throws ManticoreResponseError
	 */
	public function postprocess(callable $processor, array $args = []): void {
		try {
			$this->body = $processor($this->body, $this->data, $this->columns, ...$args);
		} catch (Throwable $e) {
			throw new ManticoreResponseError("Postprocessing function failed to run: {$e->getMessage()}");
		}
		$this->parse();
	}

	/**
	 * @return void
	 * @throws ManticoreResponseError
	 */
	protected function parse(): void {
		if (!isset($this->body)) {
			return;
		}
		$data = json_decode($this->body, true);
		if (!is_array($data)) {
			throw new ManticoreResponseError('Invalid JSON found');
		}
		if (empty($data)) {
			return;
		}
		if (array_is_list($data)) {
			/** @var array<string,string> */
			$data = $data[0];
		}
		if (array_key_exists('error', $data) && is_string($data['error']) && $data['error'] !== '') {
			$this->error = $data['error'];
		} else {
			$this->error = null;
		}
		foreach (['columns', 'data'] as $prop) {
			if (!array_key_exists($prop, $data) || !is_array($data[$prop])) {
				continue;
			}
			$this->$prop = $data[$prop];
		}
	}

	/**
	 * @param string $body
	 * @return self
	 */
	public static function buildFromBody(string $body): self {
		return new self($body);
	}
}

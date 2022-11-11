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
use Manticoresearch\Buddy\Interface\ManticoreResponseInterface;
use Throwable;

class ManticoreResponse implements ManticoreResponseInterface {

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
	 * @param string $body
	 * @return void
	 */
	public function __construct(
		protected string $body
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
		return $this->body;
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
		$bodyJSON = json_decode($this->body, true);
		if (!is_array($bodyJSON)) {
			throw new ManticoreResponseError('Invalid JSON found');
		}
		if (empty($bodyJSON)) {
			return;
		}
		if (array_is_list($bodyJSON)) {
			$bodyJSON = $bodyJSON[0];
		}
		if (array_key_exists('error', $bodyJSON) && is_string($bodyJSON['error']) && $bodyJSON['error'] !== '') {
			$this->error = $bodyJSON['error'];
		} else {
			$this->error = null;
		}
		foreach (['columns', 'data'] as $prop) {
			if (!array_key_exists($prop, $bodyJSON) || !is_array($bodyJSON[$prop])) {
				continue;
			}
			$this->$prop = $bodyJSON[$prop];
		}
	}

}

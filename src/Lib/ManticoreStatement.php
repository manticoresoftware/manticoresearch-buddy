<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Closure;
use Manticoresearch\Buddy\Enum\Action;
use Manticoresearch\Buddy\Exception\ManticoreStatementError;
use Manticoresearch\Buddy\Interface\StatementInterface;

final class ManticoreStatement implements StatementInterface {

	/**
	 * @var mixed $postprocessorGetter
	 */
	protected mixed $postprocessorGetter;

	/**
	 * @var Closure $postprocessor
	 */
	protected Closure $postprocessor;

	/**
	 * @var ?Action $action
	 */
	protected ?Action $action = null;

	/**
	 * @param ?string $body
	 * @return void
	 */
	public function __construct(
		protected ?string $body = null,
	) {
	}

	/**
	 * @param string $body
	 * @param ?mixed $postprocessorInit
	 * @param ?Action $action
	 * @return StatementInterface
	 */
	public static function create(
		string $body,
		mixed $postprocessorInit = null,
		?Action $action = null
	): StatementInterface {
		$stmt = new self($body);
		if (isset($postprocessorInit)) {
			if ($postprocessorInit instanceof Closure) {
				$stmt->setPostprocessor($postprocessorInit);
			} elseif (isset($action)) {
				$stmt->initPostprocessor([$postprocessorInit, $action]);
			}
		}
		return $stmt;
	}

	/**
	 * @return string
	 */
	public function getBody(): string {
		return (string)$this->body;
	}

	/**
	 * @param string $body
	 * @return void
	 */
	public function setBody(string $body): void {
		$this->body = $body;
	}

	/**
	 * @return bool
	 */
	public function hasPostprocessor(): bool {
		return isset($this->postprocessor) || isset($this->postprocessorGetter);
	}

	/**
	 * @return Closure|null
	 */
	public function getPostprocessor(): Closure|null {
		if (isset($this->postprocessor)) {
			return $this->postprocessor;
		}
		if (!(isset($this->action, $this->postprocessorGetter)) || !is_callable($this->postprocessorGetter)) {
			throw new ManticoreStatementError('Cannot get a postprocessor');
		}
		$postprocessor = call_user_func_array($this->postprocessorGetter, [$this->action]);
		if ($postprocessor instanceof Closure) {
			return $postprocessor;
		}
		throw new ManticoreStatementError('Invalid postprocessor passed');
	}

	/**
	 * @param Closure $processor
	 * @return void
	 */
	public function setPostprocessor(Closure $processor): void {
		$this->postprocessor = $processor;
	}

	/**
	 * @param array{0:mixed,1:Action} $postprocessorInit
	 * @return void
	 */
	protected function initPostprocessor(array $postprocessorInit): void {
		[$this->postprocessorGetter, $this->action] = $postprocessorInit;
	}

}

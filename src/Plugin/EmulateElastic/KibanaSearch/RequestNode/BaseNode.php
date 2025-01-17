<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\SphinxQLRequest;

/**
 *  Node of Kibana search request used to build the respective SphinxQL request and the further Kibana response
 */
abstract class BaseNode {

	/** @var bool $isDisabled */
	protected bool $isDisabled = false;
	/** @var string $key */
	protected string $key;
	/** @var SphinxQLRequest $request */
	protected SphinxQLRequest $request;

	/** @return string */
	public function getKey(): string {
		return $this->key;
	}

	/**
	 * @param SphinxQLRequest $request
	 * @return static
	 */
	public function setRequest(SphinxQLRequest $request): static {
		$this->request = $request;
		return $this;
	}

	/** @return void */
	public function disable(): void {
		$this->isDisabled = true;
	}

	/**
	 * @return bool
	 */
	public function isDisabled(): bool {
		return $this->isDisabled;
	}

	/**
	 * @return void
	 */
	abstract public function fillInRequest(): void;
}

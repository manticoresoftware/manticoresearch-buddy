<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Interface;

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;

interface ManticoreHTTPClientInterface {

	/**
	 * @param ManticoreResponseBuilderInterface $responseBuilder
	 * @return void
	 */
	public function setResponseBuilder(ManticoreResponseBuilderInterface $responseBuilder): void;

	/**
	 * @param string $url
	 * @return void
	 */
	public function setServerUrl(string $url): void;

	/**
	 * @param ManticoreEndpoint $endpoint
	 * @return void
	 */
	public function setEndpoint(ManticoreEndpoint $endpoint): void;

	/**
	 * @param string $request
	 * @param ?ManticoreEndpoint $endpoint
	 * @return ManticoreResponseInterface
	 */
	public function sendRequest(string $request, ManticoreEndpoint $endpoint = null): ManticoreResponseInterface;
}

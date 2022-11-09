<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Interface;

use Manticoresearch\Buddy\Enum\MntEndpoint;

interface MntHTTPClientInterface {

	/**
	 * @param MntResponseBuilderInterface $responseBuilder
	 * @return void
	 */
	public function setResponseBuilder(MntResponseBuilderInterface $responseBuilder): void;

	/**
	 * @param string $url
	 * @return void
	 */
	public function setServerUrl(string $url): void;

	/**
	 * @param MntEndpoint $endpoint
	 * @return void
	 */
	public function setEndpoint(MntEndpoint $endpoint): void;

	/**
	 * @param string $request
	 * @param ?MntEndpoint $endpoint
	 * @return MntResponseInterface
	 */
	public function sendRequest(string $request, MntEndpoint $endpoint = null): MntResponseInterface;
}

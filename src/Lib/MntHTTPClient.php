<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Enum\MntEndpoint;
use Manticoresearch\Buddy\Exception\MntHTTPClientError;
use Manticoresearch\Buddy\Interface\MntHTTPClientInterface;
use Manticoresearch\Buddy\Interface\MntResponseBuilderInterface;
use Manticoresearch\Buddy\Interface\MntResponseInterface;
use RuntimeException;

class MntHTTPClient implements MntHTTPClientInterface {

	const CONTENT_TYPE_HEADER = 'Content-Type: text/plain';
	const CUSTOM_REDIRECT_DISABLE_HEADER = 'X-Manticore-Error-Redirect: disable';
	const URL_PREFIX = 'http://';

	/**
	 * @var string $response
	 */
	protected string $response;

	/**
	 * @param ?MntResponseBuilderInterface $responseBuilder
	 * @param ?string $url
	 * @param MntEndpoint $endpoint
	 * @return void
	 */
	public function __construct(
		protected ?MntResponseBuilderInterface $responseBuilder = null,
		protected ?string $url = null,
		protected MntEndpoint $endpoint = MntEndpoint::Cli
	) {
	}

	/**
	 * @param MntResponseBuilderInterface $responseBuilder
	 * @return void
	 */
	public function setResponseBuilder(MntResponseBuilderInterface $responseBuilder): void {
		$this->responseBuilder = $responseBuilder;
	}

	/**
	 * @param string $url
	 * @return void
	 */
	public function setServerUrl($url): void {
		$origUrl = $url;
		if (!str_starts_with($url, self::URL_PREFIX)) {
			$url = self::URL_PREFIX . $url;
		}
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			throw new MntHTTPClientError("Malformed request url '$origUrl' passed");
		}
		$this->url = $url;
	}

	/**
	 * @param string $request
	 * @param ?MntEndpoint $endpoint
	 * @return MntResponseInterface
	 */
	public function sendRequest($request, MntEndpoint $endpoint = null): MntResponseInterface {
		if (!isset($this->responseBuilder)) {
			throw new RuntimeException("'responseBuilder' property of MntHTTPClient class is not instantiated");
		}
		if ($request === '') {
			throw new MntHTTPClientError('Empty request passed');
		}
		if (!isset($endpoint)) {
			$endpoint = $this->endpoint;
		}
		$fullReqUrl = "{$this->url}/{$endpoint->value}";
		$conn = curl_init($fullReqUrl);
		if ($conn === false) {
			throw new MntHTTPClientError("Cannot connect to server at $fullReqUrl");
		} else {
			curl_setopt_array(
				$conn,
				[
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_POST => 1,
					CURLOPT_POSTFIELDS => $request,
					CURLOPT_HTTPHEADER => [
						self::CONTENT_TYPE_HEADER,
						self::CUSTOM_REDIRECT_DISABLE_HEADER,
					],
				]
			);
			$this->response = (string)curl_exec($conn);
			curl_close($conn);
			if ($this->response === '') {
				throw new MntHTTPClientError('No response passed from server');
			}
		}

		return $this->responseBuilder->buildFromBody($this->response);
	}

	/**
	 * @param MntEndpoint $endpoint
	 * @return void
	 */
	public function setEndpoint(MntEndpoint $endpoint): void {
		$this->endpoint = $endpoint;
	}

}

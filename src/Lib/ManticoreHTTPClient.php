<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Exception\ManticoreHTTPClientError;
use Manticoresearch\Buddy\Lib\ManticoreResponse;
use RuntimeException;

class ManticoreHTTPClient {

	const CONTENT_TYPE_HEADER = 'Content-Type: text/plain';
	const CUSTOM_REDIRECT_DISABLE_HEADER = 'X-Manticore-Error-Redirect: disable';
	const URL_PREFIX = 'http://';
	const HTTP_REQUEST_TIMEOUT = 1;
	const DEFAULT_URL = 'http://127.0.0.1:9308';

	/**
	 * @var string $response
	 */
	protected string $response;

	/** @var string $url */
	protected string $url;

	/**
	 * @param ?ManticoreResponse $responseBuilder
	 * @param ?string $url
	 * @param ManticoreEndpoint $endpoint
	 * @return void
	 */
	public function __construct(
		protected ?ManticoreResponse $responseBuilder = null,
		?string $url = null,
		protected ManticoreEndpoint $endpoint = ManticoreEndpoint::Cli
	) {
		// If no url passed, set default one
		if (!$url) {
			$url = static::DEFAULT_URL;
		}

		$this->setServerUrl($url);
	}

	/**
	 * @param ManticoreResponse $responseBuilder
	 * @return void
	 */
	public function setResponseBuilder(ManticoreResponse $responseBuilder): void {
		$this->responseBuilder = $responseBuilder;
	}

	/**
	 * @param string $url it supports http:// prefixed and not
	 * @return void
	 */
	public function setServerUrl($url): void {
		// $origUrl = $url;
		if (!str_starts_with($url, self::URL_PREFIX)) {
			$url = self::URL_PREFIX . $url;
		}
		// ! we do not have filter extension in production version
		// if (!filter_var($url, FILTER_VALIDATE_URL)) {
			// throw new ManticoreHTTPClientError("Malformed request url '$origUrl' passed");
		// }
		$this->url = $url;
	}

	/**
	 * @param string $request
	 * @param ?ManticoreEndpoint $endpoint
	 * @return ManticoreResponse
	 */
	public function sendRequest(string $request, ManticoreEndpoint $endpoint = null): ManticoreResponse {
		if (!isset($this->responseBuilder)) {
			throw new RuntimeException("'responseBuilder' property of ManticoreHTTPClient class is not instantiated");
		}
		if ($request === '') {
			throw new ManticoreHTTPClientError('Empty request passed');
		}
		$endpoint ??= $this->endpoint;
		// Hmm, This is strange, but $endpoint === ManticoreEndpoint::Sql is not working
		$prefix = ($endpoint->name === 'Sql' ? 'query=' : '');
		$fullReqUrl = "{$this->url}/{$endpoint->value}";
		$opts = [
			'http' => [
				'method'  => 'POST',
				'header'  => 'Content-Type: application/x-www-form-urlencoded',
				'content' => $prefix . $request,
				'timeout' => static::HTTP_REQUEST_TIMEOUT,
			],
		];

		$context = stream_context_create($opts);
		$result = file_get_contents($fullReqUrl, false, $context);

		if ($result === false) {
			throw new ManticoreHTTPClientError("Cannot connect to server at $fullReqUrl");
		} else {
			$this->response = (string)$result;
			if ($this->response === '') {
				throw new ManticoreHTTPClientError('No response passed from server');
			}
		}

		return $this->responseBuilder->fromBody($this->response);
	}

	/**
	 * @param ManticoreEndpoint $endpoint
	 * @return void
	 */
	public function setEndpoint(ManticoreEndpoint $endpoint): void {
		$this->endpoint = $endpoint;
	}

}

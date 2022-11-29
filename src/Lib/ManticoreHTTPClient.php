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
use Manticoresearch\Buddy\Interface\ManticoreHTTPClientInterface;
use Manticoresearch\Buddy\Interface\ManticoreResponseBuilderInterface;
use Manticoresearch\Buddy\Interface\ManticoreResponseInterface;
use RuntimeException;

class ManticoreHTTPClient implements ManticoreHTTPClientInterface {

	const CONTENT_TYPE_HEADER = 'Content-Type: text/plain';
	const CUSTOM_REDIRECT_DISABLE_HEADER = 'X-Manticore-Error-Redirect: disable';
	const URL_PREFIX = 'http://';

	/**
	 * @var string $response
	 */
	protected string $response;

	/**
	 * @param ?ManticoreResponseBuilderInterface $responseBuilder
	 * @param ?string $url
	 * @param ManticoreEndpoint $endpoint
	 * @return void
	 */
	public function __construct(
		protected ?ManticoreResponseBuilderInterface $responseBuilder = null,
		protected ?string $url = null,
		protected ManticoreEndpoint $endpoint = ManticoreEndpoint::Cli
	) {
	}

	/**
	 * @param ManticoreResponseBuilderInterface $responseBuilder
	 * @return void
	 */
	public function setResponseBuilder(ManticoreResponseBuilderInterface $responseBuilder): void {
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
			throw new ManticoreHTTPClientError("Malformed request url '$origUrl' passed");
		}
		$this->url = $url;
	}

	/**
	 * @param string $request
	 * @param ?ManticoreEndpoint $endpoint
	 * @return ManticoreResponseInterface
	 */
	public function sendRequest(string $request, ManticoreEndpoint $endpoint = null): ManticoreResponseInterface {
		if (!isset($this->responseBuilder)) {
			throw new RuntimeException("'responseBuilder' property of ManticoreHTTPClient class is not instantiated");
		}
		if ($request === '') {
			throw new ManticoreHTTPClientError('Empty request passed');
		}
		if (!isset($endpoint)) {
			$endpoint = $this->endpoint;
		}
		$fullReqUrl = "{$this->url}/{$endpoint->value}";

		$conn = curl_init($fullReqUrl);
		if ($conn === false) {
			throw new ManticoreHTTPClientError("Cannot connect to server at $fullReqUrl");
		} else {
//			// !TODO  Check why requests to Manticore hang when using stream approach 
// 			$opts = [
// 				'http' => [
// 					'method'  => 'POST',
// 					'header'  => 'Content-Type: application/x-www-form-urlencoded',
// 					'content' => $request,
// 				],
// 			];
//
// 			$context  = stream_context_create($opts);
// 			$result = file_get_contents($fullReqUrl, false, $context);
			curl_setopt_array(
				$conn,
				[
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_POST => 1,
					CURLOPT_POSTFIELDS => $request,
					CURLOPT_HTTPHEADER => [
						'Content-Type: text/plain',
						'X-Manticore-Error-Redirect: disable',
					],
				]
			);
			$this->response = (string)curl_exec($conn);
			curl_close($conn);
			if ($this->response === '') {
				throw new ManticoreHTTPClientError('No response passed from server');
			}
		}

		return $this->responseBuilder->buildFromBody($this->response);
	}

	/**
	 * @param ManticoreEndpoint $endpoint
	 * @return void
	 */
	public function setEndpoint(ManticoreEndpoint $endpoint): void {
		$this->endpoint = $endpoint;
	}

}

<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Network;

use Manticoresearch\Buddy\Enum\MntEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\InvalidRequestError;
use ValueError;

final class Request {
	public RequestFormat $requestType;
	public MntEndpoint $endpoint;
	public RequestFormat $format;
	public string $origMsg;
	public string $query;

	/** @var array<string,string> */
	const FIELD_MAP = [
		'type' => 'origMsg',
		'message' => 'query',
		'request_type' => 'format',
	];

	/**
	 * @return void
	 */
	public function __construct() {
	}

	/**
	 * Create request from string and validate that it's ok for us
	 *
	 * @param string $data
	 * @return static
	 */
	public static function fromString(string $data): static {
		$self = new static;
		$self->parseOrFail(static::validateOrFail($data));
		return $self;
	}

	/**
	 * Helper to create request from prepare array data
	 * It can be useful for tests
	 *
	 * @param array{origMsg:string,query:string,format:RequestFormat,endpoint:MntEndpoint} $data
	 * @return static
	 */
	public static function fromArray(array $data): static {
		$self = new static;
		foreach ($data as $k => $v) {
			$self->$k = $v;
		}
		return $self;
	}

	/**
	 * Validate input data before we will parse it into a request
	 *
	 * @param string $data
	 * @return array<string,string>
	 * @throws InvalidRequestError
	 */
	public static function validateOrFail(string $data): array {
		if ($data === '') {
			throw new InvalidRequestError('Query is missing');
		}
		$reqBodyPos = strpos($data, '{');
		if ($reqBodyPos === false) {
			throw new InvalidRequestError("Request body is missing in query '{$data}'");
		}
		$query = substr($data, $reqBodyPos);
		$result = json_decode($query, true);
		if (!is_array($result)) {
			throw new InvalidRequestError("Invalid request body '{$query}' is passed");
		}

		return $result;
	}

	/**
	 * @param array<string,string> $payload
	 * @return static
	 * @throws InvalidRequestError
	 */
	protected function parseOrFail(array $payload): static {
		foreach (array_keys(self::FIELD_MAP) as $k) {
			if (!array_key_exists($k, $payload)) {
				throw new InvalidRequestError("Mandatory field '$k' is missing");
			}
			if (!is_string($payload[$k])) {
				throw new InvalidRequestError("Field '$k' must be a string");
			}
		}
		// Checking if request format and endpoint are supported
		try {
			$endpoint = MntEndpoint::from($payload['endpoint']);
			unset($payload['endpoint']);
		} catch (ValueError) {
			throw new InvalidRequestError("Unknown request endpoint '{$payload['endpoint']}'");
		}
		try {
			$requestType = RequestFormat::from($payload['request_type']);
			unset($payload['request_type']);
		} catch (\Throwable) {
			throw new InvalidRequestError("Unknown request type '{$payload['request_type']}'");
		}

		$this->requestType = $requestType;
		$this->endpoint = $endpoint;
		// Change original request field names to more informative ones
		foreach (self::FIELD_MAP as $k => $v) {
			$this->$v = $payload[$k]; // @phpstan-ignore-line
		}

		return $this;
	}
}

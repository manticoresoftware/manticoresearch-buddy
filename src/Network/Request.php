<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Network;

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\InvalidRequestError;
use ValueError;

final class Request {
	const PAYLOAD_FIELDS = ['type', 'message', 'request_type', 'endpoint', 'listen'];

	public ManticoreEndpoint $endpoint;
	public RequestFormat $format;
	public string $origMsg;
	public string $query;

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
	 * @param array{origMsg:string,query:string,format:RequestFormat,endpoint:ManticoreEndpoint} $data
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
	 * This method is same as fromArray but applied to payload
	 *
	 * @param array{type:string,message:string,request_type:string,endpoint:string,listen:string} $payload
	 * @return static
	 */
	public static function fromPayload(array $payload): static {
		$self = new static;
		return $self->parseOrFail($payload);
	}

	/**
	 * Validate input data before we will parse it into a request
	 *
	 * @param string $data
	 * @return array{type:string,message:string,request_type:string,endpoint:string,listen:string}
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
		/** @var array{type:string,message:string,request_type:string,endpoint:string,listen:string} */
		$result = json_decode($query, true);
		if (!is_array($result)) {
			throw new InvalidRequestError("Invalid request body '{$query}' is passed");
		}

		return $result;
	}

	/**
	 * @param array{type:string,message:string,request_type:string,endpoint:string,listen:string} $payload
	 * @return static
	 * @throws InvalidRequestError
	 */
	protected function parseOrFail(array $payload): static {
		foreach (static::PAYLOAD_FIELDS as $k) {
			if (!array_key_exists($k, $payload)) {
				throw new InvalidRequestError("Mandatory field '$k' is missing");
			}
			if (!is_string($payload[$k])) {
				throw new InvalidRequestError("Field '$k' must be a string");
			}
		}
		// Checking if request format and endpoint are supported
		try {
			$endpoint = ManticoreEndpoint::from($payload['endpoint']);
			unset($payload['endpoint']);
		} catch (ValueError) {
			throw new InvalidRequestError("Unknown request endpoint '{$payload['endpoint']}'");
		}
		try {
			$format = RequestFormat::from($payload['request_type']);
			unset($payload['request_type']);
		} catch (\Throwable) {
			throw new InvalidRequestError("Unknown request type '{$payload['request_type']}'");
		}

		$this->format = $format;
		$this->endpoint = $endpoint;
		// Omg? O_O ok
		$this->origMsg = $payload['type'];
		$this->query = $payload['message'];

		return $this;
	}
}

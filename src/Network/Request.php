<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Network;

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\InvalidRequestError;
use Manticoresearch\Buddy\Exception\SQLQueryParsingError;

final class Request {
	const PAYLOAD_FIELDS = [
		'type' => 'string',
		'error' => 'string',
		'message' => 'array',
		'version' => 'integer',
	];
	const MESSAGE_FIELDS = ['path_query' => 'string', 'body' => 'string'];

	/** @var int $id Request id from header Request-ID */
	public int $id;
	public float $time;

	public ManticoreEndpoint $endpointBundle;
	public RequestFormat $format;
	public string $path;
	public string $error;
	public string $payload;
	public int $version;

	/**
	 * @return void
	 */
	public function __construct() {
	}

	/**
	 * Create default filled request
	 *
	 * @param int $id
	 * @return static
	 */
	public static function default(int $id = 0): static {
		$self = new static;
		$self->id = $id;
		$self->time = microtime(true);
		$self->endpointBundle = ManticoreEndpoint::Sql;
		$self->path = ManticoreEndpoint::Sql->value;
		$self->format = RequestFormat::JSON;
		$self->error = '';
		$self->payload = '{}';
		$self->version = 1;
		return $self;
	}

	/**
	 * Create request from string and validate that it's ok for us
	 *
	 * @param string $data
	 * @param int $id
	 * @return static
	 */
	public static function fromString(string $data, int $id = 0): static {
		$self = new static;
		$self->id = $id;
		$self->time = microtime(true);
		$self->parseOrFail(static::validateOrFail($data));
		return $self;
	}

	/**
	 * Helper to create request from prepare array data
	 * It can be useful for tests
	 *
	 * @param array{
	 * 	error:string,
	 * 	payload:string,
	 * 	version:int,
	 * 	format:RequestFormat,
	 * 	endpointBundle:ManticoreEndpoint,
	 *  path:string
	 * } $data
	 * @param int $id
	 * @return static
	 */
	public static function fromArray(array $data, int $id = 0): static {
		$self = new static;
		$self->id = $id;
		$self->time = microtime(true);
		foreach ($data as $k => $v) {
			$self->$k = $v;
		}
		return $self;
	}

	/**
	 * This method is same as fromArray but applied to payload
	 *
	 * @param array{type:string,error:string,message:array{path_query:string,body:string},version:int} $payload
	 * @param int $id
	 * @return static
	 */
	public static function fromPayload(array $payload, int $id = 0): static {
		$self = new static;
		$self->id = $id;
		$self->time = microtime(true);
		return $self->parseOrFail($payload);
	}

	/**
	 * Validate input data before we will parse it into a request
	 *
	 * @param string $data
	 * @return array{type:string,error:string,message:array{path_query:string,body:string},version:int}
	 * @throws InvalidRequestError
	 */
	public static function validateOrFail(string $data): array {
		if ($data === '') {
			throw new InvalidRequestError('The payload is missing');
		}
		/** @var array{type:string,error:string,message:array{path_query:string,body:string},version:int} */
		$result = json_decode($data, true);
		if (!is_array($result)) {
			throw new InvalidRequestError('Invalid request payload is passed');
		}

		return $result;
	}

	/**
	 * @param array{type:string,error:string,message:array{path_query:string,body:string},version:int} $payload
	 * @return static
	 * @throws InvalidRequestError
	 */
	protected function parseOrFail(array $payload): static {
		static::validateInputFields($payload, static::PAYLOAD_FIELDS);

		// Checking if request format and endpoint are supported
		[$this->path] = explode('?', ltrim($payload['message']['path_query'], '/'));
		if (preg_match('/\/\_doc(\/|$)/', $this->path)
			|| preg_match('/\/\_create(\/|$)/', $this->path)) {
			// We don't differentiate elastic-like insert and replace queries here
			// since this is irrelevant for Buddy processing logic
			$endpointBundle = ManticoreEndpoint::Insert;
		} else {
			$endpointBundle = match ($this->path) {
				'cli' => ManticoreEndpoint::Cli,
				'cli_json' => ManticoreEndpoint::CliJson,
				'sql?mode=raw', 'sql', '' => ManticoreEndpoint::Sql,
				'insert', 'replace' => ManticoreEndpoint::Insert,
				'bulk', '_bulk' => ManticoreEndpoint::Bulk,
				'_license' => ManticoreEndpoint::Elastic,
				default => throw new InvalidRequestError(
					"Do not know how to handle '{$payload['message']['path_query']}' path_query"
				),
			};
		}

		$format = match ($payload['type']) {
			'unknown json request' => RequestFormat::JSON,
			'unknown sql request' => RequestFormat::SQL,
			default => throw new InvalidRequestError("Do not know how to handle '{$payload['type']}' type"),
		};

		$this->format = $format;
		$this->endpointBundle = $endpointBundle;
		$this->payload = static::removeComments($payload['message']['body']);
		$this->error = $payload['error'];
		$this->version = $payload['version'];
		return $this;
	}

	/**
	 * Helper function to do recursive validation of input fields
	 *
	 * @param array{
	 * 		path_query: string,
	 * 		body: string
	 * 	}|array{
	 * 		type:string,
	 * 		error:string,
	 * 		message:array{path_query:string,body:string},
	 * 		version:int
	 * 	} $payload
	 * @param array<string> $fields
	 * @return void
	 */
	protected function validateInputFields(array $payload, array $fields): void {
		foreach ($fields as $k => $type) {
			if (!array_key_exists($k, $payload)) {
				throw new InvalidRequestError("Mandatory field '$k' is missing");
			}

			if (gettype($payload[$k]) !== $type) {
				throw new InvalidRequestError("Field '$k' must be a $type");
			}

			if ($k !== 'message' || !is_array($payload[$k])) {
				continue;
			}

			static::validateInputFields($payload[$k], static::MESSAGE_FIELDS);
		}
	}

	/**
	 * Remove all types of comments from the query, because we do not use it for now
	 * @param string $query
	 * @return string
	 * @throws SQLQueryParsingError
	 */
	protected static function removeComments(string $query): string {
		$query = preg_replace(
			'/(?<!\')(?<=\s)(--|#)[^\r\n\'\"]*(?=[\r\n]|$)|\/\*.*?\*\//ms',
			'',
			$query
		);
		if ($query === null) {
			throw new SQLQueryParsingError('Error while removing comments from the query using regex');
		}
		/** @var string $query */
		return trim($query);
	}
}

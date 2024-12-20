<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Network;

use Ds\Vector;
use Manticoresearch\Buddy\Core\Error\InvalidNetworkRequestError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\MySQLTool;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;
use Manticoresearch\Buddy\Core\Tool\Buddy;

final class Request {
	const PAYLOAD_FIELDS = [
		'type' => 'string',
		'error' => 'array',
		'message' => 'array',
		'version' => 'integer',
	];
	const MESSAGE_FIELDS = ['path_query' => 'string', 'body' => 'string'];

	/** @var string $id Request id from header Request-ID */
	public string $id;
	public float $time;

	public Endpoint $endpointBundle;
	public RequestFormat $format;
	public Settings $settings;
	public string $path;
	public string $error;
	/** @var array<mixed> $errorBody */
	public array $errorBody;
	public string $payload;
	public string $httpMethod;
	public int $version;
	public ?MySQLTool $mySQLTool;

	/**
	 * @return void
	 */
	public function __construct() {
	}

	/**
	 * Create default filled request
	 *
	 * @param string $id
	 * @return static
	 */
	public static function default(string $id = '0'): static {
		$self = new static;
		$self->id = $id;
		$self->time = microtime(true);
		$self->endpointBundle = Endpoint::Sql;
		$self->settings = Settings::fromVector(new Vector());
		$self->path = Endpoint::Sql->value;
		$self->format = RequestFormat::JSON;
		$self->error = '';
		$self->errorBody = [];
		$self->payload = '{}';
		$self->version = Buddy::PROTOCOL_VERSION;
		return $self;
	}

	/**
	 * Create request from string and validate that it's ok for us
	 *
	 * @param string $data
	 * @param string $id
	 * @return static
	 */
	public static function fromString(string $data, string $id = '0'): static {
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
	 * 	endpointBundle:Endpoint,
	 *  path:string
	 * } $data
	 * @param string $id
	 * @return static
	 */
	public static function fromArray(array $data, string $id = '0'): static {
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
	 * @param array{
	 *  type:string,
	 *  error:array{message:string,body?:array{error:string}},
	 *  message:array{path_query:string,body:string},
	 *  version:int} $payload
	 * @param string $id
	 * @return static
	 */
	public static function fromPayload(array $payload, string $id = '0'): static {
		$self = new static;
		$self->id = $id;
		$self->time = microtime(true);
		return $self->parseOrFail($payload);
	}

	/**
	 * Validate input data before we will parse it into a request
	 *
	 * @param string $data
	 * @return array{
	 *  type:string,
	 *  error:array{message:string,body?:array{error:string}},
	 *  message:array{path_query:string,body:string},
	 *  version:int}
	 * @throws InvalidNetworkRequestError
	 */
	public static function validateOrFail(string $data): array {
		if ($data === '') {
			throw new InvalidNetworkRequestError('The payload is missing');
		}
		/** @var array{
		 * type:string,
		 * error:array{message:string,body?:array{error:string}},
		 * message:array{path_query:string,body:string},
		 * version:int} $result
		 */
		$result = json_decode($data, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
		if (!is_array($result)) {
			throw new InvalidNetworkRequestError('Invalid request payload is passed');
		}

		return $result;
	}

	/**
	 * @param array{
	 * type:string,
	 * error:array{
	 *  message:string,
	 *  body?:array{
	 *   error:string
	 *  }
	 * },
	 * message:array{
	 *  path_query:string,
	 *  body:string,
	 *  http_method?:string},
	 * version:int
	 * } $payload
	 * @return static
	 * @throws InvalidNetworkRequestError
	 */
	protected function parseOrFail(array $payload): static {
		static::validateInputFields($payload, static::PAYLOAD_FIELDS);

		// Checking if request format and endpoint are supported
		/** @var array{path:string,query?:string} $urlInfo */
		$urlInfo = parse_url($payload['message']['path_query']);
		$path = ltrim($urlInfo['path'], '/');
		if ($path === 'sql' && isset($urlInfo['query'])) {
			// We need to keep the query parameters part in the sql queries
			// as it's required for the following requests to Manticore
			$path .= '?' . $urlInfo['query'];
		} elseif (str_ends_with($path, '/_bulk') && !str_starts_with($path, '.kibana/')) {
			// Convert the elastic bulk request path to the Manticore one
			$path = '_bulk';
		}
		Buddy::debug('TEST ' . $path);
		if (static::isElasticPath($path)) {
			$endpointBundle = Endpoint::Elastic;
		} elseif (str_contains($path, '/_doc/') || str_contains($path, '/_create/')
			|| str_ends_with($path, '/_doc')) {
			// We don't differentiate elastic-like insert and replace queries here
			// since this is irrelevant for the following Buddy processing logic
			$endpointBundle = Endpoint::Insert;
		} elseif (str_contains($path, '/_update/')) {
			$endpointBundle = Endpoint::Update;
		} else {
			$endpointBundle = match ($path) {
				'bulk', '_bulk' => Endpoint::Bulk,
				'cli' => Endpoint::Cli,
				'cli_json' => Endpoint::CliJson,
				'search' => Endpoint::Search,
				'sql?mode=raw', 'sql', '' => Endpoint::Sql,
				'insert', 'replace' => Endpoint::Insert,
				'_license' => Endpoint::Elastic,
				'autocomplete' => Endpoint::Autocomplete,
				default => throw new InvalidNetworkRequestError(
					"Do not know how to handle '{$payload['message']['path_query']}' path_query"
				),
			};
		}

		$format = match ($payload['type']) {
			'unknown json request' => RequestFormat::JSON,
			'unknown sql request' => RequestFormat::SQL,
			default => throw new InvalidNetworkRequestError("Do not know how to handle '{$payload['type']}' type"),
		};
		$this->httpMethod = $payload['message']['http_method'] ?? '';
		$this->path = $path;
		$this->format = $format;
		$this->endpointBundle = $endpointBundle;
		$this->mySQLTool = static::detectMySQLTool($payload['message']['body']);
		$this->payload = (in_array($endpointBundle, [Endpoint::Elastic, Endpoint::Bulk]))
			? trim($payload['message']['body'])
			: static::removeComments($payload['message']['body']);
		$this->error = $payload['error']['message'];
		$this->errorBody = $payload['error']['body'] ?? [];
		$this->version = match ($payload['version']) {
			Buddy::PROTOCOL_VERSION => $payload['version'],
			default => throw new InvalidNetworkRequestError(
				"Buddy protocol version expects '" . Buddy::PROTOCOL_VERSION . "' but got '{$payload['version']}'"
			),
		};
		return $this;
	}

	/**
	 * Helper function to detect if request path refers to Elastic-like request
	 *
	 * @param string $path
	 * @return bool
	 */
	protected function isElasticPath(string $path): bool {
		$elasticPathPrefixes = [
			'_index_template/',
			'_xpack',
			'.kibana/',
			'_cluster',
			'_mget',
			'.kibana_task_manager',
			'_aliases',
			'_alias/',
			'_template/',
			'_cat/',
			'_field_caps',
		];
		$elasticPathSuffixes = [
			'_nodes',
			'/_mapping',
			'/_search',
			'.kibana',
			'/_field_caps',
		];
		foreach ($elasticPathPrefixes as $prefix) {
			if (str_starts_with($path, $prefix)) {
				return true;
			}
		}
		foreach ($elasticPathSuffixes as $suffix) {
			if (str_ends_with($path, $suffix)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Helper function to do recursive validation of input fields
	 *
	 * @param array{
	 * 		path_query: string,
	 * 		body: string
	 * 	}|array{
	 *      message:string,
	 *      body?:array{
	 *        error:string
	 *      }
	 *  }|array{
	 *      error:string
	 *  }|array{
	 * 		type:string,
	 * 		error:array{message:string,body?:array{error:string}},
	 * 		message:array{path_query:string,body:string},
	 * 		version:int
	 * 	} $payload
	 * @param array<string> $fields
	 * @return void
	 */
	protected function validateInputFields(array $payload, array $fields): void {
		foreach ($fields as $k => $type) {
			if (!array_key_exists($k, $payload)) {
				throw new InvalidNetworkRequestError("Mandatory field '$k' is missing");
			}

			if (gettype($payload[$k]) !== $type) {
				throw new InvalidNetworkRequestError("Field '$k' must be a $type");
			}

			if ($k !== 'message' || !is_array($payload[$k])) {
				continue;
			}

			static::validateInputFields($payload[$k], static::MESSAGE_FIELDS);
		}
	}

	/**
	 * Detect if the request is sent with some MySQLTool, like DBeaver, etc.
	 * @param string $query
	 * @return ?MySQLTool
	 */
	protected static function detectMySQLTool(string $query): ?MySQLTool {
		foreach (MySQLTool::cases() as $tool) {
			if (strpos($query, "/* ApplicationName={$tool->value}") === 0) {
				return $tool;
			}
		}

		return null;
	}

	/**
	 * Remove all types of comments from the query, because we do not use it for now
	 * @param string $query
	 * @return string
	 * @throws QueryParseError
	 */
	protected static function removeComments(string $query): string {
		$query = preg_replace_callback(
			'/((\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\')|(--[^"\r\n]*|#[^"\r\n]*|\/\*[^!][\s\S]*?\*\/))/',
			static function (array $matches): string {
				if (strpos($matches[0], '--') === 0
				|| strpos($matches[0], '#') === 0
				|| strpos($matches[0], '/*') === 0) {
					return '';
				}

				return $matches[0];
			},
			$query
		);

		if ($query === null) {
			QueryParseError::throw(
				'Error while removing comments from the query using regex: '.  preg_last_error_msg()
			);
		}
		/** @var string $query */
		return trim($query, '; ');
	}

	/**
	 * Validate if we should format the output in the Table way
	 * @return OutputFormat
	 */
	public function getOutputFormat(): OutputFormat {
		return match ($this->endpointBundle) {
			Endpoint::Cli => OutputFormat::Table,
			default => OutputFormat::Raw,
		};
	}
}

<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Exception;
use Manticoresearch\Buddy\Core\Error\InvalidNetworkRequestError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * Request for Backup command that has parsed parameters from SQL
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {

	/**
	 * @var string $requestTarget
	 */
	public static string $requestTarget;

	/**
	 * @var array<string,array{properties?:array<mixed>,type?:string,fields?:array<mixed>,
	 * dimension?:int,method?:array<mixed>}> $columnInfo
	 */
	public array $columnInfo;

	/** @var ?string $table */
	public ?string $table;

	/** @var string $path */
	public string $path;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Emulates some Elastic queries and generates responses'
		 . ' as if they were made by ES';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();

		$pathParts = explode('/', ltrim($request->path, '/'));
		static::$requestTarget = end($pathParts);
		switch (static::$requestTarget) {
			case '_license':
				break;
			case '_mapping':
				/**
				 * @var array{
				 * properties?:array<string,array{properties?:array<mixed>,type?:string,fields?:array<mixed>}>
				 * } $requestBody
				 */
				$requestBody = (array)json_decode($request->payload, true);
				if ($requestBody === [] || !isset($requestBody['properties'])) {
					throw new Exception("Unvalid request body in {$request->path}: $request->payload");
				}
				$self->columnInfo = $requestBody['properties'];
				$self->table = $pathParts[0];
				$self->path = $request->path;
				break;
			default:
				if ($pathParts[0] === '_index_template') {
					$customError = InvalidNetworkRequestError::create('', true);
					$customError->setResponseErrorCode(200);
					throw $customError;
				}
				throw new Exception("Unsupported request type in {$request->path}: " . static::$requestTarget);
		}

		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		return $request->endpointBundle === Endpoint::Elastic;
	}

	/**
	 * @return string
	 */
	public function getHandlerClassName(): string {
		$namespace = __NAMESPACE__ . '\\';
		$handlerName = match (static::$requestTarget) {
			'_license' => 'LicenseHandler',
			'_mapping' => 'CreateTableHandler',
			default => throw new Exception('Cannot find handler for request type: ' . static::$requestTarget),
		};

		return $namespace . $handlerName;
	}
}

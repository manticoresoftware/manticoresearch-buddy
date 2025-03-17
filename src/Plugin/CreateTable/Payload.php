<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\CreateTable;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @extends BasePayload<array>
 */
final class Payload extends BasePayload
{
	public static string $requestTarget;

	public string $destinationTableName;
	public string $sourceTableName;
	public bool $notExists = false;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enables tables copying; handles CREATE TABLE statements with MySQL options not supported by Manticore';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		/** Say hi to phpstan */
		unset($request);
		/**
		 * @var array{
		 *       CREATE: array{
		 *           expr_type: string,
		 *           not-exists: bool,
		 *           base_expr: string,
		 *           sub_tree?: array<array{
		 *               expr_type: string,
		 *               base_expr: string
		 *           }>
		 *       },
		 *       TABLE: array{
		 *           base_expr: string,
		 *           name?: string,
		 *           no_quotes: array{
		 *               delim: bool,
		 *               parts: array<string>
		 *           },
		 *           create-def?: bool,
		 *           options?: array<array{
		 *               expr_type: string,
		 *               base_expr: string,
		 *               delim: string,
		 *               sub_tree?: array<array{
		 *                   expr_type: string,
		 *                   base_expr: string
		 *               }>
		 *           }>
		 *       },
		 *       LIKE: array{
		 *           expr_type: string,
		 *           table?: string,
		 *           base_expr: string,
		 *           no_quotes: array{
		 *               delim: bool,
		 *               parts: array<string>
		 *           }
		 *       }
		 *   } $payload
		 */
		$payload = Payload::$sqlQueryParser::getParsedPayload();

		switch (static::$requestTarget) {
			case 'Handler':
				$self->destinationTableName = $payload['TABLE']['no_quotes']['parts'][0];
				$self->sourceTableName = $payload['LIKE']['no_quotes']['parts'][0];
				$self->notExists = $payload['CREATE']['not-exists'];
				break;
			case 'WithEngineHandler':
				$self->destinationTableName = (string)array_pop($payload['TABLE']['no_quotes']['parts']);
				break;
		}

		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 * @throws GenericError
	 */
	public static function hasMatch(Request $request): bool {
		if ($request->command !== 'create') {
			return false;
		}

		/**
		 * @var array{
		 *       CREATE?: array{
		 *           expr_type: string,
		 *           not-exists: bool,
		 *           base_expr: string,
		 *           sub_tree?: array<array{
		 *               expr_type: string,
		 *               base_expr: string
		 *           }>
		 *       },
		 *       TABLE: array{
		 *           base_expr: string,
		 *           name?: string,
		 *           no_quotes: array{
		 *               delim: bool,
		 *               parts: array<string>
		 *           },
		 *           create-def?: bool,
		 *           options?: array<array{
		 *               expr_type: string,
		 *               base_expr: string,
		 *               delim: string,
		 *               sub_tree?: array<array{
		 *                   expr_type: string,
		 *                   base_expr: string
		 *               }>
		 *           }>
		 *       },
		 *       LIKE: array{
		 *           expr_type: string,
		 *           table?: string,
		 *           base_expr: string,
		 *           no_quotes: array{
		 *               delim: bool,
		 *               parts: array<string>
		 *           }
		 *       }
		 *   }|false $payload
		 */
		$payload = Payload::$sqlQueryParser::parse(
			$request->payload,
			fn($request) => (
				stripos($request->payload, 'create') === 0
				&& stripos($request->payload, 'table') !== false
				&& (
					(
						stripos(
							$request->error,
							'P01: syntax error, unexpected CREATE near'
						) !== false
						&& stripos($request->payload, 'engine') !== false
					) || (
						stripos(
							$request->error,
							"P03: syntax error, unexpected tablename, expecting \$end near 'with data'"
						) !== false
						&& stripos($request->payload, 'like') !== false
						&& stripos($request->payload, 'with') !== false
						&& stripos($request->payload, 'data') !== false
					)
				)
			), $request
		);

		if (!$payload || !isset(
			$payload['CREATE'],
			$payload['TABLE']['no_quotes']['parts'][0],
			$payload['TABLE']['options'][0]['base_expr']
		)) {
			return false;
		}

		static::$requestTarget = match (true) {
			(isset($payload['LIKE']['no_quotes']['parts'][0])
				&& strtolower($payload['TABLE']['options'][0]['base_expr']) === 'with data') => 'Handler',
			(isset($payload['TABLE']['options'][0]['sub_tree'][0]['base_expr'])
				&& strtolower($payload['TABLE']['options'][0]['sub_tree'][0]['base_expr']) === 'engine')
				=> 'WithEngineHandler',
			default => '',
		};

		return (static::$requestTarget !== '');
	}

	/**
	 * @return string
	 */
	public function getHandlerClassName(): string {
		return __NAMESPACE__ . '\\' . static::$requestTarget;
	}

}

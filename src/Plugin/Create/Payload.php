<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Create;

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
	public string $path;

	public string $destinationTableName;
	public string $sourceTableName;
	public ?string $dataDirPath;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enables tables copying';
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

		$self->destinationTableName = $payload['TABLE']['no_quotes']['parts'][0];
		$self->sourceTableName = $payload['LIKE']['no_quotes']['parts'][0];
		$self->dataDirPath = $self->getSettings()->searchdDataDir;
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 * @throws GenericError
	 */
	public static function hasMatch(Request $request): bool {
		$payload = Payload::$sqlQueryParser::parse(
			$request->payload,
			fn($request) => (
				stripos(
					$request->error,
					"P03: syntax error, unexpected tablename, expecting \$end near 'with data'"
				) !== false
				&& stripos($request->payload, 'create') !== false
				&& stripos($request->payload, 'table') !== false
				&& stripos($request->payload, 'like') !== false
				&& stripos($request->payload, 'with') !== false
				&& stripos($request->payload, 'data') !== false
			), $request
		);

		if (!$payload) {
			return false;
		}

		if (isset($payload['CREATE'])
			&& isset($payload['TABLE']['no_quotes']['parts'][0])
			&& isset($payload['LIKE']['no_quotes']['parts'][0])
			&& isset($payload['TABLE']['options'][0]['base_expr'])
			&& strtolower($payload['TABLE']['options'][0]['base_expr']) === 'with data'
		) {
			return true;
		}

		return false;
	}
}

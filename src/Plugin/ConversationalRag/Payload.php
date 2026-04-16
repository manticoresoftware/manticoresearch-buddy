<?php declare(strict_types=1);

/*
  Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	public const string ACTION_CREATE_MODEL = 'create_model';
	public const string ACTION_SHOW_MODELS = 'show_models';
	public const string ACTION_DESCRIBE_MODEL = 'describe_model';
	public const string ACTION_DROP_MODEL = 'drop_model';
	public const string ACTION_CONVERSATION = 'conversation';

	/** @var string */
	public string $action;

	/** @var string */
	public string $query;

	/** @var array<string, string> */
	public array $params = [];

	/**
	 * Check if the request matches this plugin
	 *
	 * @param Request $request
	 *
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		// Check SQL patterns first
		if (self::matchesSQL($request)) {
			return true;
		}

		return false;
	}

	/**
	 * Check if SQL query matches RAG patterns
	 *
	 * @param Request $request
	 *
	 * @return bool
	 */
	private static function matchesSQL(Request $request): bool {
		// Check for error-based matching (ManticoreSearch tried and failed)
		if (isset($request->error)) {
			$errorBasedPatterns = [
				'no such built-in procedure CONVERSATIONAL_RAG',
				'syntax error, unexpected tablename, expecting CLUSTER or FUNCTION or PLUGIN or TABLE near \'RAG MODEL',
			];

			if (array_any($errorBasedPatterns, fn($errorPattern) => str_contains($request->error, $errorPattern))) {
				return true;
			}
		}

		// Also check direct payload patterns for CREATE RAG MODEL syntax
		$payload = strtoupper(trim($request->payload));
		$patterns = [
			'/^CALL\s+CONVERSATIONAL_RAG\s*\(/i',
			'/^CREATE\s+RAG\s+MODEL\s+/i',
			'/^SHOW\s+RAG\s+MODELS/i',
			'/^DESCRIBE\s+RAG\s+MODEL\s+/i',
			'/^DROP\s+RAG\s+MODEL\s+/i',
		];

		return array_any($patterns, fn(string $pattern): bool => preg_match($pattern, $payload) === 1);
	}


	/**
	 * Create payload from request
	 *
	 * @param Request $request
	 *
	 * @return static
	 * @throws QueryParseError
	 */
	public static function fromRequest(Request $request): static {
		$payload = new static();
		$payload->query = $request->payload;

		// Parse SQL request only (HTTP API not supported)
		$payload->parseSQLRequest($request);

		return $payload;
	}


	/**
	 * Parse SQL request
	 *
	 * @param Request $request
	 *
	 * @return void
	 * @throws QueryParseError
	 */
	private function parseSQLRequest(Request $request): void {
		$sql = trim($request->payload);
		$withoutTrailingSemicolon = preg_replace('/;\s*\z/', '', $sql);
		if ($withoutTrailingSemicolon !== null) {
			$sql = $withoutTrailingSemicolon;
		}

		// SQL syntax for model management
		if (preg_match('/^CREATE\s+RAG\s+MODEL\s+[\'"]?(\w+)[\'"]?\s*\((.*)\)\s*\z/si', $sql, $matches)) {
			$this->action = self::ACTION_CREATE_MODEL;
			$this->params = $this->parseCreateModelParams($matches[1], $matches[2]);
		} elseif (preg_match('/^SHOW\s+RAG\s+MODELS\s*\z/i', $sql)) {
			$this->action = self::ACTION_SHOW_MODELS;
		} elseif (preg_match('/^DESCRIBE\s+RAG\s+MODEL\s+[\'"]?([^\'"]+)[\'"]?\s*\z/i', $sql, $matches)) {
			$this->action = self::ACTION_DESCRIBE_MODEL;
			$this->params = ['model_name_or_uuid' => $matches[1]];
		} elseif (preg_match('/^DROP\s+RAG\s+MODEL\s+/i', $sql)) {
			$this->action = self::ACTION_DROP_MODEL;
			$this->params = $this->parseDropModelParams($sql);
		} elseif (preg_match('/^CALL\s+CONVERSATIONAL_RAG\s*\((.*)\)\s*\z/si', $sql, $matches)) {
			$this->action = self::ACTION_CONVERSATION;
			$this->params = $this->parseConversationParams($matches[1]);
		} else {
			throw new QueryParseError('Invalid RAG query syntax');
		}
	}

	/**
	 * Parse CREATE RAG MODEL parameters
	 *
	 * @param string $modelName
	 * @param string $params
	 *
	 * @return array<string, string>
	 * @throws QueryParseError
	 */
	private function parseCreateModelParams(string $modelName, string $params): array {
		// Parse key=value pairs from CREATE RAG MODEL syntax
		$config = ['identifier' => $modelName];
		$paramPairs = $this->parseKeyValueParams($params);

		foreach ($paramPairs as $key => $value) {
			$config[$key] = $this->unquoteString($value);
		}

		return $config;
	}

	/**
	 * Parse key=value parameters
	 *
	 * @param string $params
	 *
	 * @return array<string, string>
	 * @throws QueryParseError
	 */
	private function parseKeyValueParams(string $params): array {
		$result = [];
		$segments = $this->parseCommaSeparatedParams($params);

		foreach ($segments as $segment) {
			$segment = trim($segment);
			if ($segment === '') {
				continue;
			}

			// Split on first = sign
			$parts = explode('=', $segment, 2);
			if (sizeof($parts) !== 2) {
				throw QueryParseError::create("Invalid parameter '$segment', expected key=value");
			}

			$key = strtolower(trim($parts[0]));
			$value = trim($parts[1]);
			$result[$key] = $value;
		}

		return $result;
	}

	/**
	 * Parse comma-separated parameters with quote handling
	 *
	 * @param string $params
	 *
	 * @return array<string>
	 */
	private function parseCommaSeparatedParams(string $params): array {
		$result = [];
		$current = '';
		$depth = 0;
		$inQuotes = false;
		$quoteChar = '';

		$i = 0;
		while ($i < strlen($params)) {
			$char = $params[$i];

			[$inQuotes, $quoteChar, $current, $charsConsumed, $skipAppend] = $this->handleCharacterInQuotes(
				$char, $inQuotes, $quoteChar, $current, $params, $i
			);

			if (!$inQuotes && $char === ',' && $depth === 0) {
				// We found a parameter separator
				$result[] = trim($current);
				$current = '';
			} elseif (!$inQuotes) {
				// Handle other non-quote characters outside quotes
				if ($char === '(' || $char === '{' || $char === '[') {
					$depth++;
				} elseif ($char === ')' || $char === '}' || $char === ']') {
					$depth--;
				}
				$current .= $char;
			} elseif (!$skipAppend) {
				// We're in quotes and should append this character
				$current .= $char;
			}

			$i += $charsConsumed;
		}

		if (!empty(trim($current))) {
			$result[] = trim($current);
		}

		return $result;
	}

	/**
	 * Handle character processing when inside quotes
	 *
	 * @param string $char
	 * @param bool $inQuotes
	 * @param string $quoteChar
	 * @param string $current
	 * @param string $params
	 * @param int $i
	 *
	 * @return array{0: bool, 1: string, 2: string, 3: int, 4: bool}
	 */
	private function handleCharacterInQuotes(
		string $char,
		bool $inQuotes,
		string $quoteChar,
		string $current,
		string $params,
		int $i
	): array {
		$charsConsumed = 1;
		// Check for escaped characters when in quotes
		if ($inQuotes && $char === '\\' && $i + 1 < strlen($params)) {
			// Add both the escape and the escaped character
			$current .= $char;
			$current .= $params[$i + 1];
			$charsConsumed = 2;
			return [true, $quoteChar, $current, $charsConsumed, true];
		}

		if (!$inQuotes && ($char === '"' || $char === "'")) {
			$inQuotes = true;
			$quoteChar = $char;
		} elseif ($inQuotes && $char === $quoteChar) {
			$inQuotes = false;
			$quoteChar = '';
		}

		return [$inQuotes, $quoteChar, $current, $charsConsumed, false];
	}

	/**
	 * Remove quotes from string
	 *
	 * @param string $str
	 *
	 * @return string
	 */
	private function unquoteString(string $str): string {
		$str = trim($str);

		if ((str_starts_with($str, '"')
				&& str_ends_with($str, '"'))
			|| (str_starts_with($str, "'")
				&& str_ends_with($str, "'"))
		) {
			$unquoted = substr($str, 1, -1);
			// Handle SQL escaped quotes
			$unquoted = str_replace("\\'", "'", $unquoted);
			return str_replace('\\"', '"', $unquoted);
		}

		return $str;
	}

	/**
	 * @return array{model_name_or_uuid: string, if_exists?: '1'}
	 * @throws QueryParseError
	 */
	private function parseDropModelParams(string $sql): array {
		if (!preg_match(
			'/^DROP\s+RAG\s+MODEL\s+(?:(IF\s+EXISTS)\s+)?(?:\'([^\']+)\'|"([^"]+)"|([A-Za-z0-9_-]+))\s*\z/i',
			$sql,
			$matches
		)
		) {
			throw QueryParseError::create('Invalid DROP RAG MODEL syntax');
		}

		$modelNameOrUuid = $matches[2] ?? '';
		if ($modelNameOrUuid === '') {
			$modelNameOrUuid = $matches[3] ?? '';
		}
		if ($modelNameOrUuid === '') {
			$modelNameOrUuid = $matches[4] ?? '';
		}
		if ($modelNameOrUuid === '') {
			throw QueryParseError::create('DROP RAG MODEL requires model name or uuid');
		}

		$params = ['model_name_or_uuid' => $modelNameOrUuid];
		if (!empty($matches[1])) {
			$params['if_exists'] = '1';
		}

		return $params;
	}

	/**
	 * Parse conversation parameters
	 *
	 * @param string $params
	 *
	 * @return array<string, string>
	 * @throws QueryParseError
	 */
	private function parseConversationParams(string $params): array {
		$parts = $this->parseCommaSeparatedParams($params);

		$result = [
			'query' => $this->unquoteString($parts[0] ?? ''),
			'table' => $this->unquoteString($parts[1] ?? ''),
			'model_uuid' => $this->unquoteString($parts[2] ?? ''),
		];

		if (isset($parts[3])) {
			$result['conversation_uuid'] = $this->unquoteString($parts[3]);
		}
		if (isset($parts[4])) {
			throw QueryParseError::create('CONVERSATIONAL_RAG expects query, table, model, optional conversation_uuid');
		}

		return $result;
	}


}

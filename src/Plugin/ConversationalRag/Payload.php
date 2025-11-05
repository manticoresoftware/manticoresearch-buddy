<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

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
	public const ACTION_CREATE_MODEL = 'create_model';
	public const ACTION_SHOW_MODELS = 'show_models';
	public const ACTION_DESCRIBE_MODEL = 'describe_model';
	public const ACTION_DROP_MODEL = 'drop_model';
	public const ACTION_CONVERSATION = 'conversation';

	/** @var string */
	public string $action;

	/** @var string */
	public string $query;

	/** @var array */
	public array $params = [];

	/**
	 * Check if the request matches this plugin
	 *
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		// Debug: log that our hasMatch is being called
		error_log('[ConversationalRag] hasMatch called with payload: ' . substr($request->payload, 0, 100));
		error_log('[ConversationalRag] Request error: ' . ($request->error ?? 'none'));

		// Check SQL patterns first
		if (self::matchesSQL($request)) {
			error_log('[ConversationalRag] SQL pattern matched!');
			return true;
		}



		error_log('[ConversationalRag] No patterns matched');
		return false;
	}

	/**
	 * Check if SQL query matches RAG patterns
	 *
	 * @param Request $request
	 * @return bool
	 */
	private static function matchesSQL(Request $request): bool {
		// Check for error-based matching (ManticoreSearch tried and failed)
		if (isset($request->error)) {
			$errorBasedPatterns = [
				'no such built-in procedure CONVERSATIONAL_RAG',
				'syntax error, unexpected tablename, expecting CLUSTER or FUNCTION or PLUGIN or TABLE near \'RAG MODEL',
			];

			foreach ($errorBasedPatterns as $errorPattern) {
				if (str_contains($request->error, $errorPattern)) {
					return true;
				}
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

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $payload)) {
				return true;
			}
		}

		return false;
	}



	/**
	 * Create payload from request
	 *
	 * @param Request $request
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
	 * @return void
	 * @throws QueryParseError
	 */
	private function parseSQLRequest(Request $request): void {
		$sql = trim($request->payload);

		// SQL syntax for model management
		if (preg_match('/^CREATE\s+RAG\s+MODEL\s+[\'"]?(\w+)[\'"]?\s*\((.*)\)$/si', $sql, $matches)) {
			$this->action = self::ACTION_CREATE_MODEL;
			$this->params = $this->parseCreateModelParams($matches[1], $matches[2]);
		} elseif (preg_match('/^SHOW\s+RAG\s+MODELS$/i', $sql)) {
			$this->action = self::ACTION_SHOW_MODELS;
		} elseif (preg_match('/^DESCRIBE\s+RAG\s+MODEL\s+[\'"]?([^\'"]+)[\'"]?$/i', $sql, $matches)) {
			$this->action = self::ACTION_DESCRIBE_MODEL;
			$this->params = ['model_name_or_uuid' => $matches[1]];
		} elseif (preg_match('/^DROP\s+RAG\s+MODEL\s+[\'"]?([^\'"]+)[\'"]?$/i', $sql, $matches)) {
			$this->action = self::ACTION_DROP_MODEL;
			$this->params = ['model_name_or_uuid' => $matches[1]];
		} elseif (preg_match('/^CALL\s+CONVERSATIONAL_RAG\s*\((.*)\)$/si', $sql, $matches)) {
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
	 * @return array
	 */
	private function parseCreateModelParams(string $modelName, string $params): array {
		// Parse key=value pairs from CREATE RAG MODEL syntax
		$config = ['name' => $modelName];
		$paramPairs = $this->parseKeyValueParams($params);

		foreach ($paramPairs as $key => $value) {
			if ($key === 'name') {
				$config['display_name'] = $this->unquoteString($value);
			} else {
				$config[$key] = $this->unquoteString($value);
			}
		}

		// Handle JSON settings
		if (isset($config['settings'])) {
			$config['settings'] = json_decode($config['settings'], true) ?? [];
		}

		if (!isset($config['name'])) {
			$config['name'] = $modelName;
		}

		return $config;
	}

	/**
	 * Parse key=value parameters
	 *
	 * @param string $params
	 * @return array
	 */
	private function parseKeyValueParams(string $params): array {
		$result = [];
		$lines = array_map('trim', explode(',', $params));

		foreach ($lines as $line) {
			if (empty($line)) {
				continue;
			}

			// Split on first = sign
			$parts = explode('=', $line, 2);
			if (sizeof($parts) !== 2) {
				continue;
			}

			$key = trim($parts[0]);
			$value = trim($parts[1]);
			$result[$key] = $value;
		}

		return $result;
	}

	/**
	 * Remove quotes from string
	 *
	 * @param string $str
	 * @return string
	 */
	private function unquoteString(string $str): string {
		$str = trim($str);

		if ((str_starts_with($str, '"')
		&& str_ends_with($str, '"'))
			|| (str_starts_with($str, "'")
			&& str_ends_with($str, "'"))) {
			$unquoted = substr($str, 1, -1);
			// Handle SQL escaped quotes
			$unquoted = str_replace("\\'", "'", $unquoted);
			$unquoted = str_replace('\\"', '"', $unquoted);
			return $unquoted;
		}

		return $str;
	}

	/**
	 * Parse conversation parameters
	 *
	 * @param string $params
	 * @return array
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
			$overridesStr = $this->unquoteString($parts[4]);
			$result['overrides'] = json_decode($overridesStr, true) ?? [];
		}

		return $result;
	}

	/**
	 * Parse comma-separated parameters, respecting quotes and parentheses
	 *
	 * @param string $params
	 * @return array
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
			$charsConsumed = 1;
			$skipAppend = false;

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
	 * @return array
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
		$skipAppend = false;

		// Check for escaped characters when in quotes
		if ($inQuotes && $char === '\\' && $i + 1 < strlen($params)) {
			// Add both the escape and the escaped character
			$current .= $char;
			$current .= $params[$i + 1];
			$charsConsumed = 2;
			$skipAppend = true;
			return [$inQuotes, $quoteChar, $current, $charsConsumed, $skipAppend];
		}

		if (!$inQuotes && ($char === '"' || $char === "'")) {
			$inQuotes = true;
			$quoteChar = $char;
		} elseif ($inQuotes && $char === $quoteChar) {
			$inQuotes = false;
			$quoteChar = '';
		}

		return [$inQuotes, $quoteChar, $current, $charsConsumed, $skipAppend];
	}


}

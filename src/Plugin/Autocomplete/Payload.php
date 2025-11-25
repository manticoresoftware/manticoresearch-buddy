<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Autocomplete;

use Exception;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;
use Manticoresearch\Buddy\Core\Tool\KeyboardLayout;
use Manticoresearch\Buddy\Core\Tool\Strings;

/**
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	/** @var string */
	public string $table;

	/** @var string */
	public string $query;

	/** @var int */
	public int $fuzziness = 2;

	/** @var bool */
	public bool $prepend = true;

	/** @var bool */
	public bool $append = true;

	/** @var int */
	public int $expansionLen = 10;

	/** @var array<string> */
	public array $layouts;

	/** @var bool */
	public bool $preserve = false;

	/** @var bool */
	public bool $forceBigrams = false;

	public function __construct() {
	}

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Autocomplete plugin that offers suggestions based on the starting query';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		return match ($request->endpointBundle) {
			Endpoint::Autocomplete => static::fromJsonRequest($request),
			default => static::fromSqlRequest($request),
		};
	}

	/**
	 * Parse the request from the given JSON
	 * @param Request $request
	 * @return static
	 */
	protected static function fromJsonRequest(Request $request): static {
		/** @var array{
			query?: string|mixed,
			table?: string|mixed,
			options?: array{
					fuzziness?: int,
					append?: int,
					prepend?: int,
					expansion_len?: int,
					layouts?: string,
					preserve?: int,
					force_bigrams?: int
			}
		} $payload */
		$payload = simdjson_decode($request->payload, true);
		if (!isset($payload['query']) || !is_string($payload['query'])) {
			throw QueryParseError::create('Failed to parse query: make sure you have query and it is a string');
		}

		if (!isset($payload['table']) || !is_string($payload['table'])) {
			throw QueryParseError::create('Failed to parse query: make sure you have table and it is a string');
		}

		$self = new static();
		$self->query = $payload['query'];
		$self->table = $payload['table'];
		$self->fuzziness = (int)($payload['options']['fuzziness'] ?? 2);
		$self->prepend = !!($payload['options']['prepend'] ?? true);
		$self->append = !!($payload['options']['append'] ?? true);
		$self->expansionLen = (int)($payload['options']['expansion_len'] ?? 10);
		$self->layouts = static::parseLayouts($payload['options']['layouts'] ?? null);
		$self->preserve = !!($payload['options']['preserve'] ?? false);
		$self->forceBigrams = !!($payload['options']['force_bigrams'] ?? false);
		$self->validate();
		return $self;
	}

	/**
	 * Validate and throw error in case some parameters are not valid
	 * @return void
	 * @throws QueryParseError
	 */
	private function validate(): void {
		if ($this->fuzziness < 0 || $this->fuzziness > 2) {
			throw QueryParseError::create('Fuzziness must be greater than 0 and lower than 3');
		}

		if ($this->expansionLen < 0 || $this->expansionLen > 20) {
			throw QueryParseError::create('Expansion limit must be greater than 0 and lower than 20');
		}
	}

	/**
	 * Parse the request from the given SQL
	 * @param Request $request
	 * @return static
	 */
	protected static function fromSqlRequest(Request $request): static {
		$pattern = '/autocomplete\(\s*\'((?:\\\\\'|[^\'])*)\'\s*,\s*\'([^\']+)\'\s*'
			. '((?:,\s*(?:(\d+)|\'([^\']*)\')\s+as\s+(\w+))*)\s*\)/ius';
		preg_match($pattern, $request->payload, $matches);
		if (!$matches) {
			throw QueryParseError::create('Failed to parse query');
		}

		$self = new static();
		$self->query = stripslashes($matches[1]);
		$self->table = $matches[2];
		if (isset($matches[3])) {
			$self->parseOptions($matches[3]);
		}
		// Make sure that we set default values for options
		if (!isset($self->layouts)) {
			$self->layouts = KeyboardLayout::getSupportedLanguages();
		}
		return $self;
	}

	/**
	 * @param string $optionString
	 * @return void
	 * @throws Exception
	 */
	protected function parseOptions(string $optionString): void {
		if (!$optionString) {
			return;
		}

		preg_match_all('/,\s*((\d+|\'[^\']*\')\s+as\s+([\w_]+))/ius', $optionString, $optionMatches, PREG_SET_ORDER);
		foreach ($optionMatches as $optionMatch) {
			$value = trim($optionMatch[2]);
			$key = $optionMatch[3];

			// Remove quotes if the value is a string
			if (strpos($value, "'") === 0) {
				$value = trim($value, "'");
			}

			/** @var string $value */
			$key = Strings::underscoreToCamelcase($key);
			if (!property_exists($this, $key) || $key === 'query' || $key === 'table') {
				QueryParseError::throw("Unknown option '$key'");
			}
			$value = static::castOption($key, $value);
			$this->{$key} = $value;
		}
	}

	/**
	 * Cast the option to the its type declared in OPTIONS constant
	 * @param string $key
	 * @param string $value
	 * @return mixed
	 * @throws Exception
	 */
	private static function castOption(string $key, string $value): mixed {
		if ($key === 'layouts') {
			$value = static::parseLayouts($value);
		}
		if ($key === 'fuzziness' || $key === 'expansionLen') {
			$value = (int)$value;
		}

		if ($key === 'prepend' || $key === 'append' || $key === 'preserve' || $key === 'forceBigrams') {
			$value = (bool)$value;
		}

		return $value;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$hasMatch = $request->error === 'no such built-in procedure AUTOCOMPLETE'
			&& stripos($request->payload, 'CALL AUTOCOMPLETE(') === 0
		;

		if (!$hasMatch) {
			$hasMatch = $request->endpointBundle === Endpoint::Autocomplete
				&& str_contains($request->error, 'unsupported endpoint');
		}

		return $hasMatch;
	}

	/**
	 * Helper to parse the lang string into array
	 * @param null|string|array<string> $layouts
	 * @return array<string>
	 * @throws QueryParseError
	 */
	protected static function parseLayouts(null|string|array $layouts): array {
		// If we have array already, just return it
		if (is_array($layouts)) {
			return $layouts;
		}
		if (isset($layouts)) {
			$layouts = $layouts ? array_map('trim', explode(',', $layouts)) : [];
		} else {
			$layouts = KeyboardLayout::getSupportedLanguages();
		}

		if ($layouts && sizeof($layouts) < 2) {
			throw QueryParseError::create(
				'At least two languages are required in layouts'
			);
		}
		return $layouts;
	}
}

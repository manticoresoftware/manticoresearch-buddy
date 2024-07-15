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
	public int $fuzziness;

	/** @var bool */
	public bool $prepend;

	/** @var bool */
	public bool $append;

	/** @var int */
	public int $expansionLimit;

	/** @var array<string> */
	public array $layouts;

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
					expansion_limit?: int,
					layouts?: string
			}
		} $payload */
		$payload = json_decode($request->payload, true);
		if (!isset($payload['query']) || !is_string($payload['query'])) {
			throw new QueryParseError('Failed to parse query: make sure you have query and it is a string');
		}

		if (!isset($payload['table']) || !is_string($payload['table'])) {
			throw new QueryParseError('Failed to parse query: make sure you have table and it is a string');
		}

		$self = new static();
		$self->query = $payload['query'];
		$self->table = $payload['table'];
		$self->fuzziness = (int)($payload['options']['fuzziness'] ?? 2);
		$self->prepend = !!($payload['options']['prepend'] ?? true);
		$self->append = !!($payload['options']['append'] ?? true);
		$self->expansionLimit = (int)($payload['options']['expansion_limit'] ?? 10);
		$self->layouts = static::parseLayouts($payload['options']['layouts'] ?? null);
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
			throw new QueryParseError('Fuzziness must be greater than 0 and lower than 3');
		}

		if ($this->expansionLimit < 0 || $this->expansionLimit > 20) {
			throw new QueryParseError('Expansion limit must be greater than 0 and lower than 20');
		}
	}

	/**
	 * Parse the request from the given SQL
	 * @param Request $request
	 * @return static
	 */
	protected static function fromSqlRequest(Request $request): static {
		$pattern = '/autocomplete\('
			. '\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'\s*'
			. '((?:,\s*(?:(\d+)\s+as\s+(\w+)|\'([^\']+)\'\s+as\s+(\w+))\s*)*)\)/ius';
		preg_match($pattern, $request->payload, $matches);
		if (!$matches) {
			throw new QueryParseError('Failed to parse query');
		}

		$self = new static();
		$self->query = $matches[1];
		$self->table = $matches[2];
		$self->parseOptions($matches);
		return $self;
	}

	/**
	 * @param array<int,string> $matches
	 * @return void
	 * @throws Exception
	 */
	protected function parseOptions(array $matches): void {
		$matchesLen = sizeof($matches);
		if ($matchesLen <= 4) {
			return;
		}

		for ($i = 4; $i < $matchesLen; $i += 2) {
			$value = $matches[$i];
			$key = $matches[$i + 1];
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
		if ($key === 'fuzziness' || $key === 'expansion_limit') {
			$value = (int)$value;
		}

		if ($key === 'prepend' || $key === 'append') {
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
	 */
	protected static function parseLayouts(null|string|array $layouts): array {
		// If we have array already, just return it
		if (is_array($layouts)) {
			return $layouts;
		}
		if (isset($layouts)) {
			$layouts = array_map('trim', explode(',', $layouts));
		} else {
			$layouts = KeyboardLayout::getSupportedLanguages();
		}
		return $layouts;
	}
}

<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\ModifyTable;

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	public string $type;
	public string $path;
	public string $cluster;
	public string $table;
	public string $structure;
	public string $extra;
	/** @var array<string,int|string> */
	public array $options;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Assists in standardizing options in create and alter table statements'
			. ' to show option=1 for integers.';
	}

  /**
	 * @param Request $request
	 * @return static
	 * @throws QueryParseError
	 */
	public static function fromRequest(Request $request): static {
		$pattern = '/(?:CREATE\s+TABLE|ALTER\s+TABLE)\s+'
			. '(?:(?P<cluster>[^:\s]+):)?(?P<table>[^:\s\()]+)\s*'
			. '(?:\((?P<structure>.+?)\)\s*)?'
			. '(?P<extra>.*)/ius';
		if (!preg_match($pattern, $request->payload, $matches)) {
			throw QueryParseError::create('Failed to parse query');
		}

		$options = [];
		if ($matches['extra']) {
			// Perform the regex match
			$pattern = '/(?P<key>[a-zA-Z_]+)\s*=\s*(?P<value>"[^"]+"|\'[^\']+\'|\S+)/';
			if (!preg_match_all($pattern, $matches['extra'], $optionMatches, PREG_SET_ORDER)) {
				QueryParseError::throw('Failed to parse options');
			}

			foreach ($optionMatches as $optionMatch) {
				$key = strtolower($optionMatch['key']);
				// Trim quotes if the value is quoted
				$value = trim($optionMatch['value'], "\"'");
				$options[$key] = $value;
			}
		}

		$self = new static();
		// We just need to do something, but actually its' just for PHPstan
		$self->path = $request->path;
		$self->type = stripos($request->payload, 'create') === 0 ? 'create' : 'alter';
		$self->table = $matches['table'];
		$self->structure = $matches['structure'];
		$self->options = $options;
		$self->extra = static::buildExtra($self->options);
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$hasMatch = stripos($request->error, 'syntax error')
			&& strpos($request->error, 'P03') === 0
			&& (
				stripos($request->payload, 'create table') === 0
					|| stripos($request->payload, 'alter table') === 0
				)
			;
		if (!$hasMatch) {
			return false;
		}

		$payload = preg_replace(
			[
				'/(?P<key>rf|shards)\s*=\s*(?P<value>\d+)/',
				'/(?P<key>[a-zA-Z_]+)\s*=\s*\'(?P<value>[^\']+)\'/',
			],
			'', $request->payload
		);
		$payload = trim($payload ?? '', ' ;');
		$lastChar = substr($payload, -1);
		if ($lastChar !== ')' || strpos('0123456789', $lastChar) === false) {
			return false;
		}
		$pattern = '/(?P<key>[A-Za-z_]+)\s*=\s*(?P<value>\'[^\']*\')/';
		return substr($payload, -1) !== ')' && !preg_match($pattern, $payload);
	}

	/**
	 * Build the extra as string of options from the options itself
	 * @param  array<string,int|string>  $options
	 * @return string
	 */
	protected static function buildExtra(array $options): string {
		$extra = '';
		foreach ($options as $key => $value) {
			// Skip sharding related info
			if ($key === 'rf' || $key === 'shards') {
				$extra .= "$key = $value ";
				continue;
			}
			$extra .= "$key = '$value' ";
		}

		return trim($extra);
	}
}

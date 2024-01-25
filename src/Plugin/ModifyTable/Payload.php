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
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$pattern = '/(?:CREATE\s+TABLE|ALTER\s+TABLE)\s+'
			. '(?:(?P<cluster>[^:\s]+):)?(?P<table>[^:\s\()]+)\s*'
			. '(?:\((?P<structure>.+?)\)\s*)?'
			. '(?P<extra>.*)/ius';
		if (!preg_match($pattern, $request->payload, $matches)) {
			QueryParseError::throw('Failed to parse query');
		}

		$options = [];
		if ($matches['extra']) {
			$pattern = '/(?P<key>[a-zA-Z_]+)\s*=\s*(?P<value>"[^"]+"|\'[^\']+\'|\S+)/';

			// Perform the regex match
			if (!preg_match_all($pattern, $matches['extra'], $optionMatches, PREG_SET_ORDER)) {
				QueryParseError::throw('Failed to parse options');
			}

			foreach ($optionMatches as $optionMatche) {
			// Trim quotes if the value is quoted
				$value = trim($optionMatche['value'], "\"'");
				if (in_array($optionMatche['key'], ['rf', 'shards'])) {
					$value = (int)$value;
				}
				$options[$optionMatche['key']] = $value;
			}
		}

		$self = new static();
		// We just need to do something, but actually its' just for PHPstan
		$self->path = $request->path;
		$self->type = stripos($request->payload, 'create') === 0 ? 'create' : 'alter';
		$self->cluster = $matches['cluster'] ?? '';
		$self->table = $matches['table'];
		$self->structure = $matches['structure'];
		$self->options = $options;
		$self->extra = static::buildExtra($self->options);
		$self->validate();
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		return stripos($request->error, 'syntax error')
			&& strpos($request->error, 'P03') === 0
			&& (
				stripos($request->payload, 'create table') === 0
					|| stripos($request->payload, 'alter table') === 0
				)
			;
	}

	/**
	 * Run query parsed data validation
	 * @return void
	 */
	protected function validate(): void {
		if (!$this->cluster && $this->options['rf'] > 1) {
			throw QueryParseError::create('You cannot set rf greater than 1 when creating single node sharded table.');
		}
	}

	/**
	 * Convert the current state into array
	 * that we use for args in event
	 * @return array{
	 * table:array{cluster:string,name:string,structure:string,extra:string},
	 * replicationFactor:int,
	 * shardCount:int
	 * }
	 */
	public function toShardArgs(): array {
		return [
			'table' => [
				'cluster' => $this->cluster,
				'name' => $this->table,
				'structure' => $this->structure,
				'extra' => $this->extra,
			],
			'replicationFactor' => (int)($this->options['rf'] ?? 1),
			'shardCount' => (int)($this->options['shards'] ?? 2),
		];
	}

	/**
	 * Validate that the current payload with sharding and should be processed
	 * @return bool
	 */
	public function withSharding(): bool {
		 return $this->type === 'create'
				&& isset($this->options['rf'])
				&& isset($this->options['shards']);
	}

	/**
	 * Build the extra as string of options from the options itself
	 * @param  array<string,int|string>  $options
	 * @return string
	 */
	protected static function buildExtra(array $options): string {
		$extra = '';
		foreach ($options as $key => $value) {
			$extra .= "$key = '$value' ";
		}

		return trim($extra);
	}
}

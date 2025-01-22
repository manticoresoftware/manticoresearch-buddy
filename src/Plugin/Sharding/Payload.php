<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

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
	public bool $quiet;
	/** @var array<string,int|string> */
	public array $options;

	/**
	 * Get processors to run
	 * @return array<Processor>
	 */
	public static function getProcessors(): array {
		static $processors;
		if (!isset($processors)) {
			$processors = [
				new Processor(),
			];
		}
		return $processors;
	}

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enables sharded tables.';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		return match ($request->command) {
			'create', 'alter' => static::fromCreate($request),
			'drop' => static::fromDrop($request),
			'desc', 'show' => static::fromDesc($request),
			default => throw new QueryParseError('Failed to parse query'),
		};
	}

	/**
	 * @param Request $request
	 * @return static
	 * @throws QueryParseError
	 */
	protected static function fromDesc(Request $request): static {
		$pattern = '/(?:DESC|SHOW\s+CREATE\s+TABLE)\s+(?P<table>[^:\s\()]+)/ius';
		if (!preg_match($pattern, $request->payload, $matches)) {
			throw QueryParseError::create('Failed to parse query');
		}

		$self = new static();
		$self->path = $request->path;
		$self->type = $request->command;
		$self->cluster = '';
		$self->table = $matches['table'];
		$self->structure = '';
		$self->options = [];
		$self->quiet = false;
		$self->extra = '';
		$self->validate();
		return $self;
	}

	/**
	 * @param Request $request
	 * @return static
	 * @throws QueryParseError
	 */
	protected static function fromCreate(Request $request): static {
		$pattern = '/(?:CREATE\s+TABLE|ALTER\s+TABLE)\s+'
			. '(?:(?P<cluster>[^:\s]+):)?(?P<table>[^:\s\()]+)\s*'
			. '(?:\((?P<structure>.+?)\)\s*)?'
			. '(?P<extra>.*)/ius';
		if (!preg_match($pattern, $request->payload, $matches)) {
			QueryParseError::throw('Failed to parse query');
		}

		/** @var array{table:string,cluster?:string,structure:string,extra:string} $matches */
		$options = [];
		if ($matches['extra']) {
			$pattern = '/(?P<key>rf|shards|timeout)\s*=\s*(?P<value>\'?\d+\'?)/ius';
			if (preg_match_all($pattern, $matches['extra'], $optionMatches, PREG_SET_ORDER)) {
				foreach ($optionMatches as $optionMatch) {
					$key = strtolower($optionMatch['key']);
					$value = (int)$optionMatch['value'];
					$options[$key] = $value;
				}
			}
			// Clean up extra from extracted options
			$matches['extra'] = trim(preg_replace($pattern, '', $matches['extra']) ?? '');
		}

		$self = new static();
		// We just need to do something, but actually its' just for PHPstan
		$self->path = $request->path;
		$self->type = $request->command === 'create' ? 'create' : 'alter';
		$self->cluster = $matches['cluster'] ?? '';
		$self->table = $matches['table'];
		$self->structure = $matches['structure'];
		$self->options = $options;
		$self->extra = $matches['extra'];
		$self->validate();
		return $self;
	}

	/**
	 * @param Request $request
	 * @return static
	 * @throws QueryParseError
	 */
	protected static function fromDrop(Request $request): static {
		$pattern = '/DROP\s+SHARDED\s+TABLE\s+(?P<quiet>IF\s+EXISTS\s+)?'
			. '(?:(?P<cluster>[^:\s]+):)?(?P<table>[^:\s\()]+)/ius';
		if (!preg_match($pattern, $request->payload, $matches)) {
			throw QueryParseError::create('Failed to parse query');
		}

		$self = new static();
		$self->path = $request->path;
		$self->type = 'drop';
		$self->quiet = !!$matches['quiet'];
		$self->cluster = $matches['cluster'];
		$self->table = $matches['table'];
		$self->structure = '';
		$self->options = [];
		$self->extra = '';
		$self->validate();
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		// Desc and Show distributed table first
		if ($request->command === 'desc' && strpos($request->error, 'contains system') !== false) {
			return true;
		}
		if ($request->command === 'show' && strpos($request->error, 'error in your query') !== false) {
			return true;
		}
		// Create and Drop
		return stripos($request->error, 'syntax error')
			&& strpos($request->error, 'P03') === 0
			&& (
				(stripos($request->payload, 'create table') === 0
					&& stripos($request->payload, 'shards') !== false
					&& preg_match('/(?P<key>rf|shards)\s*=\s*(?P<value>[\'"]?\d+[\'"]?)/ius', $request->payload)
				) || stripos($request->payload, 'drop') === 0
			);
	}

	/**
	 * Run query parsed data validation
	 * @return void
	 */
	protected function validate(): void {
		if ($this->type === 'create' && !isset($this->options['rf'])) {
			throw QueryParseError::create('Sharded table requires `rf=n`');
		}

		if (!$this->cluster && ($this->type === 'create' || $this->type === 'alter') && $this->options['rf'] > 1) {
			throw QueryParseError::create('You cannot set rf greater than 1 when creating single node sharded table.');
		}

		if (isset($this->options['timeout']) && $this->options['timeout'] < 0) {
			throw QueryParseError::create('You cannot set timeout less than 0');
		}
	}

	/**
	 * Get sharding timeout
	 * @return int
	 */
	public function getShardingTimeout(): int {
		return (int)($this->options['timeout'] ?? 30);
	}

	/**
	 * Convert the current state into array
	 * that we use for args in event
	 * @return array{
	 * table:array{cluster:string,name:string,structure:string,extra:string},
	 * replicationFactor:int,
	 * shardCount:int
	 * }|array{table:array{cluster:string,name:string}}
	 */
	public function toHookArgs(): array {
		return match ($this->type) {
			'create' => [
				'table' => [
					'cluster' => $this->cluster,
					'name' => $this->table,
					'structure' => $this->structure,
					'extra' => $this->extra,
				],
				'replicationFactor' => (int)($this->options['rf'] ?? 1),
				'shardCount' => (int)($this->options['shards'] ?? 2),
			],
			'drop' => [
				'table' => [
					'cluster' => $this->cluster,
					'name' => $this->table,
				],
			],
			default => throw new \Exception('Unsupported sharding type'),
		};
	}

	/**
	 * Get handler class to process depending on the type
	 * @return string
	 */
	public function getHandlerClassName(): string {
		return match ($this->type) {
			'create' => CreateHandler::class,
			'drop' => DropHandler::class,
			'desc' => DescHandler::class,
			default => throw new \Exception('Unsupported sharding type'),
		};
	}
}

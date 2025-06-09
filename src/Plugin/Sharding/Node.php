<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use RuntimeException;

final class Node {
	const LISTEN_PATTERN = '/^(?:' .
		'(?:' .
		'(?:[0-9]{1,3}\.){3}[0-9]{1,3}|' .
		'(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*' .
		'[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]' .
		')?:)?' .
		'([0-9]+)' .
		'(?:\:http)?$' .
		'/ius';

	/** @var int */
	public readonly int $seenAt;
	public readonly string $status;

	/**
	 * Initialize the node with current client that used to get all data
	 * @param State $state
	 * @param string $id
	 * @return void
	 */
	public function __construct(
		protected State $state,
		public readonly string $id
	) {
		$row = $this->load();
		$this->seenAt = $row['seen_at'] ?? 0;
		$this->status = $row['status'] ?? 'new';
	}

	/**
	 * Get current node identified that represents host:port
	 * @param Client $client
	 * @return string
	 */
	public static function findId(Client $client): string {
		$settings = $client->getSettings();
		$nodeId = '';
		if (!isset($settings->searchdListen)) {
			throw new RuntimeException('Settings searchdListen parameter must be set');
		}
		$listen = $settings->searchdListen->copy();
		$listen->sort(
			static function (string $a, string $b): int {
				return substr_count($a, ':') <=> substr_count($b, ':');
			}
		);
		foreach ($listen as $line) {
			$nodeId = static::parseNodeId($line);
			if ($nodeId) {
				break;
			}
		}

		// This is critical and if no node id we cannot continue
		if (!$nodeId) {
			throw new RuntimeException('Node ID detection failed');
		}

		return $nodeId;
	}

	/**
	 * Parse node id from the line
	 * @param string $line
	 * @return null|string
	 */
	public static function parseNodeId(string $line): ?string {
		if (!preg_match(static::LISTEN_PATTERN, $line, $matches)) {
			return null;
		}

		if (str_contains($line, ':')) {
			$parts = explode(':', $line);
			if (sizeof($parts) === 2 && is_numeric($parts[0])) {
				$host = '127.0.0.1';
				[$port] = $parts;
			} else {
				[$host, $port] = $parts;
			}
		} else {
			$host = '127.0.0.1';
			$port = $line;
		}

		if ($host === '0.0.0.0') {
			$hostname = gethostname();
			$host = gethostbyname($hostname ?: '');
		}
		return "$host:$port";
	}

	/**
	 * Update the row for the node
	 * @param array{seen_at?:int,status?:string} $update
	 * @return static
	 */
	public function update(array $update): static {
		$stateKey = $this->getStateKey();
		/** @var ?array<mixed> $row */
		$row = $this->state->get($stateKey);
		$update = array_replace(
			$row ?: [],
			$update
		);
		$this->state->set($stateKey, $update);
		return $this;
	}

	/**
	 * Get the node seen_at value by it"s id
	 * @return array{seen_at?:int,status?:string}
	 */
	protected function load(): array {
		/** @var ?array{shards:\Ds\Vector<int>,seen_at:int} $row */
		$row = $this->state->get($this->getStateKey());
		return $row ?? [];
	}

	/**
	 * Which key in the state we use for the current node
	 * @return string
	 */
	protected function getStateKey(): string {
		return "node:{$this->id}";
	}
}

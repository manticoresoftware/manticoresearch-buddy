<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Sharding;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use RuntimeException;

final class Node {
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
		$listen->sort();
		foreach ($listen as $line) {
			if (!preg_match('/^([0-255]\.[0-255]\.[0-255]\.[0-255]:)?([0-9]+)$/ius', $line)) {
				continue;
			}

			if (str_contains($line, ':')) {
				[$ip, $port] = explode(':', $line);
			} else {
				$ip = '127.0.0.1';
				$port = $line;
			}

			if ($ip === '0.0.0.0') {
				$hostname = gethostname();
				$ip = gethostbyname($hostname ?: '');
			}
			$nodeId = "$ip:$port";
			break;
		}

		// This is critical and if no node id we cannot continue
		if (!$nodeId) {
			throw new RuntimeException('Node ID detection failed');
		}

		return $nodeId;
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

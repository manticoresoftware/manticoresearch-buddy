<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles;

use Manticoresearch\Buddy\Base\Plugin\Sharding\Node;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Queue;

/**
 * Test double for Queue class - enables mocking of final class
 * This wrapper delegates all calls to the actual Queue instance
 * Used only in tests to bypass final class mocking limitations
 */
class TestableQueue {

	/** @var array<array{node: string, query: string, rollback_query: string, operation_group: string|null}> */
	private array $capturedCommands = [];

	/** @var callable|null */
	private $commandCallback = null;

	public function __construct(private ?Queue $queue = null, ?callable $commandCallback = null) {
		// Allow null for pure mocking scenarios
		$this->capturedCommands = [];
		$this->commandCallback = $commandCallback;
	}

	/**
	 * Get the underlying queue instance
	 * @return Queue|null
	 */
	public function getQueue(): ?Queue {
		return $this->queue;
	}

	/**
	 * Set wait for ID for queue dependencies
	 * @param int $waitForId
	 * @return static
	 */
	public function setWaitForId(int $waitForId): static {
		$this->queue?->setWaitForId($waitForId);
		return $this;
	}

	/**
	 * Reset wait for ID
	 * @return static
	 */
	public function resetWaitForId(): static {
		$this->queue?->resetWaitForId();
		return $this;
	}

	/**
	 * Add new query for requested node to the queue with rollback support
	 * @param string $nodeId
	 * @param string $query
	 * @param string $rollbackQuery
	 * @param string|null $operationGroup
	 * @return int the queue id
	 */
	public function add(
		string $nodeId,
		string $query,
		string $rollbackQuery = '',
		?string $operationGroup = null
	): int {
		// Capture command for testing
		$id = sizeof($this->capturedCommands) + 1;
		$this->capturedCommands[] = [
			'id' => $id,
			'node' => $nodeId,
			'query' => $query,
			'rollback_query' => $rollbackQuery,
			'operation_group' => $operationGroup ?? '',
			'status' => 'created',
		];

		// Call external callback if provided
		if ($this->commandCallback) {
			($this->commandCallback)(
				[
				'id' => $id,
				'node' => $nodeId,
				'query' => $query,
				'wait_for_id' => null,
				]
			);
		}

		// Delegate to real queue if available
		return $this->queue?->add($nodeId, $query, $rollbackQuery, $operationGroup) ?? $id;
	}

	/**
	 * Get the single row by id
	 * @param int $id
	 * @return array<string,mixed>
	 */
	public function getById(int $id): array {
		return $this->queue?->getById($id) ?? [];
	}

	/**
	 * Process the queue for node
	 * @param Node $node
	 * @return void
	 */
	public function process(Node $node): void {
		$this->queue?->process($node);
	}

	/**
	 * Setup the initial tables for the system cluster
	 * @return void
	 */
	public function setup(): void {
		$this->queue?->setup();
	}

	/**
	 * Rollback entire operation group
	 * @param string $operationGroup
	 * @return bool
	 */
	public function rollbackOperationGroup(string $operationGroup): bool {
		return $this->queue?->rollbackOperationGroup($operationGroup) ?? true;
	}

	/**
	 * Get captured commands for testing
	 * @return array<array{node: string, query: string, rollback_query: string, operation_group: string|null}>
	 */
	public function getCapturedCommands(): array {
		return $this->capturedCommands;
	}
}

<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Test\Plugin\Sharding\TestDoubles;

use Manticoresearch\Buddy\Base\Plugin\Sharding\Node;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Queue;

/**
 * Test double for Queue class - enables mocking of final class
 * This wrapper delegates all calls to the actual Queue instance
 * Used only in tests to bypass final class mocking limitations
 */
class TestableQueue {

	public function __construct(private ?Queue $queue = null) {
		// Allow null for pure mocking scenarios
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
	 * Add new query for requested node to the queue
	 * @param string $nodeId
	 * @param string $query
	 * @return int the queue id
	 */
	public function add(string $nodeId, string $query): int {
		return $this->queue?->add($nodeId, $query) ?? 0;
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
}

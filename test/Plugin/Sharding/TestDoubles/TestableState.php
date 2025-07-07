<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Test\Plugin\Sharding\TestDoubles;

use Ds\Vector;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Cluster;
use Manticoresearch\Buddy\Base\Plugin\Sharding\State;

/**
 * Test double for State class - enables mocking of final class
 * This wrapper delegates all calls to the actual State instance
 * Used only in tests to bypass final class mocking limitations
 */
class TestableState {

	public function __construct(private ?State $state = null) {
		// Allow null for pure mocking scenarios
	}

	/**
	 * Set cluster for state operations
	 * @param Cluster $cluster
	 * @return static
	 */
	public function setCluster(Cluster $cluster): static {
		$this->state?->setCluster($cluster);
		return $this;
	}

	/**
	 * Set the state key with related value
	 * @param string $key
	 * @param mixed $value
	 * @return static
	 */
	public function set(string $key, mixed $value): static {
		$this->state?->set($key, $value);
		return $this;
	}

	/**
	 * Get current state variable or default
	 * @param string $key
	 * @return mixed
	 */
	public function get(string $key): mixed {
		return $this->state?->get($key);
	}

	/**
	 * Get list of items by provided regex
	 * @param string $regex
	 * @return Vector<array{key:string,value:mixed}>
	 */
	public function listRegex(string $regex): Vector {
		return $this->state?->listRegex($regex) ?? new Vector();
	}

	/**
	 * Setup the initial tables for the system cluster
	 * @return void
	 */
	public function setup(): void {
		$this->state?->setup();
	}

	/**
	 * Check if state is active
	 * @return bool
	 */
	public function isActive(): bool {
		return $this->state?->isActive() ?? false;
	}
}

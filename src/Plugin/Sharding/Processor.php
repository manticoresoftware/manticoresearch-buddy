<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Manticoresearch\Buddy\Core\Process\BaseProcessor;
use Manticoresearch\Buddy\Core\Tool\Buddy;

final class Processor extends BaseProcessor {
	protected Operator $operator;
	protected int $tickerId;

	/**
	 * Start the processor
	 * @return void
	 */
	public function start(): void {
		Buddy::debugv('Starting sharding processor');
		$this->operator = new Operator($this->client, '');
		parent::start();
		$this->tickerId = $this->addTicker(fn() => $this->execute('ping'), 1);
	}

	/**
	 * Stop the processor
	 * @return void
	 */
	public function stop(): void {
		Buddy::debugv('Stopping sharding processor');
		parent::stop();
	}

	/**
	 * We execute this method in thread periodically
	 * @return bool
	 */
	public function ping(): bool {
		$operator = $this->operator;
		$nodeId = $operator->node->id;
		$hasSharding = $operator->hasSharding();
		$sharded = $hasSharding ? 'yes' : 'no';
		Buddy::debugv("Node ID: $nodeId");
		Buddy::debugv("Sharded: $sharded");

		// Do nothing when sharding is disabled
		if (!$operator->hasSharding()) {
			return false;
		}

		// Do nothing if the cluster is not synced yet
		$cluster = $operator->getCluster();
		if (!$cluster->isActive()) {
			Buddy::info('Cluster is syncing');
			return false;
		}

		$operator->processQueue();

		// hearbeat and mark current node state
		$operator->heartbeat()->checkMaster();

		// If this is not master
		/** @var string */
		$master = $operator->state->get('master');
		Buddy::debugv("Master: $master");
		if ($master !== $nodeId) {
			return false;
		}

		// Next only on master
		$operator->checkBalance();

		return false;
	}

	/**
	 * Process the shard event and create table routines
	 * @param  mixed ...$args
	 * @return void
	 */
	public function shard(mixed ...$args): void {
		/** @var array{table:array{cluster:string,name:string,structure:string,extra:string},shardCount:int,replicationFactor:int} $args */
		$this->operator->shard(...$args);
	}

	/**
	 * Validate the final status of the sharded table
	 * @param string $table
	 * @return bool True when is done and false when need to repeat
	 */
	public function status(string $table): bool {
		// Do nothing, if we have no sharding
		if (!$this->operator->hasSharding()) {
			return true;
		}
		return $this->operator->checkTableStatus($table);
	}
}

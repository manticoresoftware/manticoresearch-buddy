<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Sharding;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Psr\Container\ContainerInterface;

final class Process {
	/** @var ContainerInterface */
	protected static ContainerInterface $container;

	/**
	 * Setter for container property
	 *
	 * @param ContainerInterface $container
	 *  The container object to resolve the executor's dependencies in case such exist
	 * @return void
	 *  The CommandExecutorInterface to execute to process the final query
	 */
	public static function setContainer(ContainerInterface $container): void {
		self::$container = $container;
	}

	/**
	 * We execute this method in thread periodically
	 * @return void
	 */
	public static function ping(): void {
		$operator = static::getOperator();
		$nodeId = $operator->node->id;
		$hasSharding = $operator->hasSharding();
		$sharded = $hasSharding ? 'yes' : 'no';
		Buddy::debugv("Node ID: $nodeId");
		Buddy::debugv("Sharded: $sharded");

		// Do nothing when sharding is disabled
		if (!$operator->hasSharding()) {
			return;
		}

		// Do nothing if the cluster is not synced yet
		$cluster = $operator->getCluster();
		if (!$cluster->isActive()) {
			Buddy::info('Cluster is syncing');
			return;
		}

		$operator->processQueue();

		// hearbeat and mark current node state
		$operator->heartbeat()->checkMaster();

		// If this is not master
		/** @var string */
		$master = $operator->state->get('master');
		Buddy::debugv("Master: $master");
		if ($master !== $nodeId) {
			return;
		}

		// Next only on master
		$operator->checkBalance();
	}

	/**
	 * Process the shard event and create table routines
	 * @param  mixed ...$args
	 * @return void
	 */
	public static function shard(mixed ...$args): void {
		/** @var array{table:array{cluster:string,name:string,structure:string,extra:string},shardCount:int,replicationFactor:int} $args */
		static::getOperator()->shard(...$args);
	}

	/**
	 * Validate the final status of the sharded table
	 * @param  string $table
	 * @return bool True when is done and false when need to repeat
	 */
	public static function status(string $table): bool {
		return static::getOperator()->checkTableStatus($table);
	}

	/**
	 * Helper to initialize and get operator for sharding
	 * @return Operator
	 */
	private static function getOperator(): Operator {
		static $operator;
		if (!isset($operator)) {
			/** @var Client */
			$client = static::$container->get('manticoreClient');
			$operator = new Operator($client, '');
		}
		return $operator;
	}
}

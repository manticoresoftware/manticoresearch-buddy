<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Sharding;

use Ds\Map;
use Ds\Vector;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Psr\Container\ContainerInterface;
use Throwable;
use parallel\Channel;

/**
 * <code>
 * 	$thread = ShardingThread::start();
 * </code>
 */
final class Thread {
	/** @var static */
	protected static self $instance;

	/** @var ContainerInterface */
	protected static ContainerInterface $container;

	/**
	 * @param Task $task
	 * @return void
	 */
	public function __construct(protected Task $task) {
	}

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
	 * @return void
	 */
	public static function destroy(): void {
		if (!isset(static::$instance)) {
			return;
		}

		static::$instance->task->destroy();
	}

	/**
	 * Get single instance of the thread for metrics
	 *
	 * @return static
	 */
	public static function instance(): static {
		if (!isset(static::$instance)) {
			try {
				static::$instance = static::start();
			} catch (Throwable $e) {
				$msg = $e->getMessage();
				Buddy::debug("Failed to initialize sharding thread: $msg");
				throw $e;
			}
		}

		return static::$instance;
	}

	/**
	 * Create runtime for running as a single thread and return instance
	 *
	 * @return self
	 */
	// phpcs:ignore SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh
	protected static function start(): self {
		$task = Task::loopInRuntime(
			Task::createRuntime(),
			// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
			// phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			static function (Channel $ch, string $container) {
				$container = unserialize($container);
				/** @var ContainerInterface $container */

				// This fix issue when we get "sh: 1: cd: can't cd to" error
				// while running buddy inside directory that are not allowed for us
				chdir(sys_get_temp_dir());

				Process::setContainer($container);
				$ticks = new Vector;
				try {
					start: while ($msg = $ch->recv()) {
						if (!is_array($msg)) {
							throw new \Exception('Incorrect data received');
						}
						[$method, $args] = $msg;
						Process::$method(...$args);
						if ($method === 'shard') {
							$ticks->push(
								new Map(
									[
									'fn' => Process::status(...),
									'args' => [$args['table']['name']],
									]
								)
							);
						}

						// Run ticks after ping execution
						if ($method !== 'ping') {
							continue;
						}

						foreach ($ticks as $n => $tick) {
							try {
								$result = $tick['fn'](...$tick['args']);
							} catch (Throwable $e) {
								Buddy::debug("Error while processing tick: {$e->getMessage()}");
								continue;
							}

							if (!$result) {
								continue;
							}

							$ticks->remove($n);
						}
					}
				} catch (Throwable $e) {
					Buddy::debug(
						"Error while processing sharding: {$e->getMessage()}."
							. ' Restarting after 5s'
					);
					Buddy::debug($e->getTraceAsString());
					sleep(5); // <-- add extra protection delay
					goto start;
				}
			}, [serialize(static::$container)]
		);
		return (new self($task))->run();
	}

	/**
	 * @return self
	 */
	public function run(): self {
		$this->task->run();
		return $this;
	}

	/**
	 * Send event to the sharding thread
	 * @param string $event
	 * @param mixed[] $args
	 * @return static
	 */
	public function execute(string $event, array $args = []): static {
		$argsJson = json_encode($args);
		Buddy::debug("Sharding Event: $event $argsJson");
		$this->task->transmit([$event, $args]);
		return $this;
	}
}

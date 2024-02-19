<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Sharding;

use Ds\Map;
use Ds\Vector;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Psr\Container\ContainerInterface;
use Swoole\Process as SwooleProcess;
use Throwable;

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
	 * @param SwooleProcess $process
	 * @return void
	 */
	public function __construct(public readonly SwooleProcess $process) {
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

		static::$instance->process->exit();
	}

	/**
	 * Get single instance of the thread for metrics
	 *
	 * @return static
	 */
	public static function instance(): static {
		if (!isset(static::$instance)) {
			try {
				static::$instance = static::create();
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
	public static function create(): self {
		$process = new SwooleProcess(
			static function (SwooleProcess $worker) {
				chdir(sys_get_temp_dir());

				Process::setContainer(static::$container);
				$ticks = new Vector;
				try {
					start: while ($msg = $worker->read()) {
						if (!is_string($msg)) {
							throw new \Exception('Incorrect data received');
						}
						$msg = unserialize($msg);
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

						// Each  tick here should return TRUE when done working
						// or FALSE when it shoud be repeated on next ping
						foreach ($ticks as $n => $tick) {
							try {
								$shouldRemove = $tick['fn'](...$tick['args']);
								if ($shouldRemove) {
									$ticks->remove($n);
								}
							} catch (Throwable $e) {
								Buddy::debug("Error while processing tick: {$e->getMessage()}");
								continue;
							}
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
			}, true, 2
		);
		return new self($process);
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
		$this->process->write(serialize([$event, $args]));
		return $this;
	}
}

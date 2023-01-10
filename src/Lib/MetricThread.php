<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Lib;

use Psr\Container\ContainerInterface;
use parallel\Channel;
use parallel\Runtime;

// This is class that allows us to run separate thread for telemetry collection
/**
 * You should check Metric component to find out which methods are available
 *
 * <code>
 * 	$thread = MetricThread::start();
 * 	$thread->execute("add", ["hello", 34]);
 * 	# your code goes here
 * 	$thread->execute("add", ["hello", 34]);
 * 	# some code and finally we can send it
 * 	$thread->execute("send");
 * </code>
 */
final class MetricThread {
	/** @var static */
	protected static self $instance;

	/** @var ContainerInterface */
	// We set this on initialization (init.php) so we are sure we have it in class
	protected static ContainerInterface $container;


	/**
	 * @param Runtime $runtime
	 * @return void
	 */
	public function __construct(protected Runtime $runtime, protected Channel $channel, protected Task $task) {
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
		static::instance()->channel->close();
		static::instance()->runtime->kill();
	}

	/**
	 * Get single instance of the thread for metrics
	 *
	 * @return static
	 */
	public static function instance(): static {
		if (!isset(static::$instance)) {
			static::$instance = static::start();
		}

		return static::$instance;
	}

	/**
	 * Create runtime for running as a single thread and return instance
	 *
	 * @return self
	 */
	public static function start(): self {
		$runtime = Task::createRuntime();
		$channel = new Channel();
		$task = Task::createInRuntime(
			$runtime, static function (Channel $ch, ContainerInterface $container) {
				Metric::setContainer($container);
				$metric = Metric::instance();
				while ($msg = $ch->recv()) {
					if (!is_array($msg)) {
						throw new \Exception('Incorrect data received');
					}
					[$method, $args] = $msg;
					$metric->$method(...$args);
				}
			}, [$channel, static::$container]
		);

		return (new self($runtime, $channel, $task))->run();
	}

	/**
	 * @return self
	 */
	public function run(): self {
		$this->task->run();
		return $this;
	}

	/**
	 * Executor of the Metric component in separate thread
	 *
	 * @param string $method
	 *  Which method we want to execute
	 * @param mixed[] $args
	 *  Arguments that will be expanded to pass to the method
	 * @return static
	 */
	public function execute(string $method, array $args = []): static {
		$argsJson = json_encode($args);
		debug("metric: $method $argsJson");
		$this->channel->send([$method, $args]);
		return $this;
	}
}

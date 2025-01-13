<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Lib;

use Manticoresearch\Buddy\Core\Process\ProcessReader;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\Strings;
use Psr\Container\ContainerInterface;
use Swoole\Process;

// This is class that allows us to run separate thread for telemetry collection
/**
 * You should check Metric component to find out which methods are available
 *
 * <code>
 * 	$thread = MetricThread::create();
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

	public function __construct(public readonly Process $process) {
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
			static::$instance = static::create();
		}

		return static::$instance;
	}

	/**
	 * Create runtime for running as a single thread and return instance
	 *
	 * @return self
	 */
	public static function create(): self {
		$process = new Process(
			static function (Process $worker) {
				$name = Buddy::getProcessName(Strings::classNameToIdentifier(static::class));
				swoole_set_process_name($name);
				chdir(sys_get_temp_dir());

				/** @var \Manticoresearch\Buddy\Core\ManticoreSearch\Client $client */
				$client = static::$container->get('manticoreClient');
				$metric = Metric::instance($client);
				while (true) {
					$reader = ProcessReader::read($worker);
					foreach ($reader as $msg) {
						if (!is_string($msg)) {
							throw new \Exception('Incorrect data received');
						}
						$msg = unserialize($msg);
						if (!is_array($msg)) {
							throw new \Exception('Incorrect data received');
						}
						[$method, $args] = $msg;
						$metric->$method(...$args);
					}
				}
			}, true, 2, true
		);
		return new self($process);
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
		Buddy::debugv("metric: $method " . json_encode($args));
		$packet = ProcessReader::packMessage([$method, $args]);
		$this->process->write($packet);
		return $this;
	}
}

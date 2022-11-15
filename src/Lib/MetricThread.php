<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Lib;

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
	/**
	 * @param Runtime $runtime
	 * @return void
	 */
	public function __construct(protected Runtime $runtime, protected Channel $channel, protected Task $task) {
	}

	/**
	 * Create runtime for running as a single thread and return instance
	 *
	 * @return self
	 */
	public static function start(): self {
		$runtime = new Runtime(__DIR__ . '/../../vendor/autoload.php');
		$channel = new Channel();
		$task = Task::createInRuntime(
			$runtime, static::class, function (Channel $ch) {
				$metric = Metric::instance();
				while ($msg = $ch->recv()) {
					if (!is_array($msg)) {
						throw new \Exception('Incorrect data received');
					}

					[$method, $args] = $msg;
					$metric->$method(...$args);
				}
			}, [$channel]
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
		$this->channel->send([$method, $args]);
		return $this;
	}
}

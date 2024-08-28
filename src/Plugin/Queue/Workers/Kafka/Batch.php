<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Workers\Kafka;

use Closure;

class Batch {
	protected int $batchSize;

	/**
	 * @var array<int, mixed>
	 */
	private array $batch = [];

	/**
	 * @var Closure(array<int, string>): bool $closure
	 */
	private Closure $callback;

	private int $lastCallTime = 0;

	public function __construct(int $batchSize = 50) {
		$this->setBatchSize($batchSize);
	}

	public function setBatchSize(int $batchSize): void {
		$this->batchSize = $batchSize;
	}

	/**
	 * @param Closure(array<int, string>): bool $closure
	 * @return void
	 */
	public function setCallback(Closure $closure): void {
		$this->callback = $closure;
	}

	public function checkProcessingTimeout(): bool {
		return $this->lastCallTime + 10 < time();
	}

	public function add(mixed $item): bool {
		$this->lastCallTime = time();
		$this->batch[] = $item;

		if (sizeof($this->batch) < $this->batchSize) {
			return false;
		}
		return $this->process();
	}

	public function process(): bool {
		if (empty($this->batch)) {
			return false;
		}
		$run = call_user_func($this->callback, $this->batch);
		$this->batch = [];
		return $run;
	}
}

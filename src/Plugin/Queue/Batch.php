<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Closure;
use Manticoresearch\Buddy\Core\Tool\Buddy;

class Batch
{
	protected int $batchSize;

	private array $batch = [];

	private Closure $callback;

	private int $lastCallTime = 0;

	public function __construct(int $batchSize = 50) {
		$this->setBatchSize($batchSize);
	}

	public function setBatchSize(int $batchSize): void {
		$this->batchSize = $batchSize;
	}

	public function setCallback(callable $closure): void {
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
		Buddy::debugv('-----> KafkaWorker start batch processing. Size: ' . sizeof($this->batch));
		$run = call_user_func($this->callback, $this->batch);
		$this->batch = [];
		return $run;
	}
}

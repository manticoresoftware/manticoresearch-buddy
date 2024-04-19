<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models;

abstract class Model
{
	private array $parsedPayload;

	final public function __construct(array $parsedPayload) {
		$this->parsedPayload = $parsedPayload;
	}

	final public function getPayload(): array {
		return $this->parsedPayload;
	}

	abstract public function getHandlerClass(): string;
}

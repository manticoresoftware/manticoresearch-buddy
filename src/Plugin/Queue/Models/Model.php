<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models;

/**
 * @template T of array
 */
abstract class Model
{
	/**
	 * @phpstan-var T $parsedPayload
	 */
	private array $parsedPayload;

	/**
	 * @phpstan-param T $parsedPayload
	 */
	final public function __construct(array $parsedPayload) {
		$this->parsedPayload = $parsedPayload;
	}

	/**
	 * @phpstan-return T array
	 */
	final public function getPayload(): array {
		return $this->parsedPayload;
	}

	abstract public function getHandlerClass(): string;
}

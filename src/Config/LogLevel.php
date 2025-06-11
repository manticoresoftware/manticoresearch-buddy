<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Config;

/**
 * Represents log levels with bidirectional mapping between int values and string names
 */
enum LogLevel: int {
	case Info = 0;
	case Debug = 1;
	case Debugv = 2;
	case Debugvv = 3;

	/**
	 * Get the string representation of the log level
	 * @return string
	 */
	public function toString(): string {
		return match ($this) {
			self::Info => 'info',
			self::Debug => 'debug',
			self::Debugv => 'debugv',
			self::Debugvv => 'debugvv',
		};
	}

	/**
	 * Create an enum instance from a string name
	 * @param  string $name
	 * @return static
	 */
	public static function fromString(string $name): static {
		return match (strtolower($name)) {
			'info' => self::Info,
			'debug' => self::Debug,
			'debugv' => self::Debugv,
			'debugvv' => self::Debugvv,
			default => throw new \InvalidArgumentException("Invalid log level $name"),
		};
	}
}

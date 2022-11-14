<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Lib;

use Manticoresoftware\Telemetry\Metric as TelemetryMetric;

final class Metric {
	/** @var static $instance */
	public static self $instance;

	/** @var TelemetryMetric $telemetry */
	protected TelemetryMetric $telemetry;


	/**
	 * @param array<string,string> $labels
	 * @param bool $enabled
	 * @return void
	 */
	public function __construct(array $labels, public bool $enabled) {
		$this->telemetry = new TelemetryMetric($labels);
	}
	/**
	 * Intialize the singleton of the Metric instance and use it
	 *
	 * @return static
	 */
	public static function instance(): static {
		if (isset(static::$instance)) {
			return static::$instance;
		}

		$enabled = true;
		# TODO: use Buddy component constant
		$labels = [
			'version' => '0.0.0',
		];

		// No telemetry enabled?
		if (getenv('TELEMETRY', true) !== '1') {
			$enabled = false;
		}

		static::$instance = new static($labels, $enabled);

		return static::$instance;
	}

	/**
	 * Get current state of the metric if it's enabled or not
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}

	/**
	 * We add metric in case it's enabled otherwise
	 * this method does nothing at all
	 *
	 * @see TelemetryMetric::add
	 */
	public function add(string $name, int|float $value): static {
		if ($this->isEnabled()) {
			$this->telemetry->add($name, $value);
		}
		return $this;
	}

	/**
	 * @see TelemetryMetric::send
	 */
	public function send(): bool {
		return $this->telemetry->send();
	}
}

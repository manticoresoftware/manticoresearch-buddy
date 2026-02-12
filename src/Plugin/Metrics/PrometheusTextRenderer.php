<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics;

/**
 * @phpstan-type MetricData array{value: mixed, label?: array<string, float|int|string>}
 * @phpstan-type Metric array{type: string, info: string, data: MetricData[], deprecated_use?: string}
 */
final class PrometheusTextRenderer {

	/**
	 * @param array<string, Metric> $metrics
	 */
	public static function render(array $metrics): string {
		$result = [];
		foreach ($metrics as $metricName => $metric) {
			$info = (string)$metric['info'];
			$type = (string)$metric['type'];

			$deprecatedUse = $metric['deprecated_use'] ?? null;
			if (is_string($deprecatedUse) && $deprecatedUse !== '') {
				$result[] = "# DEPRECATED: manticore_$metricName use manticore_$deprecatedUse\n";
			}
			$result[] = "# HELP manticore_$metricName $info\n";
			$result[] = "# TYPE manticore_$metricName $type\n";

			foreach ($metric['data'] as $data) {
				$label = self::formatLabel($data['label'] ?? null);
				$value = self::stringifyValue(self::formatValue($data['value']));
				$result[] = "manticore_$metricName$label $value\n";
			}
		}

		return implode('', $result);
	}

	/**
	 * @param array<string, float|int|string>|null $label
	 */
	private static function formatLabel(?array $label): string {
		if ($label === null || $label === []) {
			return '';
		}

		$parts = [];
		foreach ($label as $name => $value) {
			$escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$value);
			$parts[] = $name . '="' . $escaped . '"';
		}

		return '{' . implode(', ', $parts) . '}';
	}

	private static function formatValue(mixed $value): mixed {
		if (in_array($value, ['OFF', '-'], true)) {
			return 0;
		}

		return $value;
	}

	private static function stringifyValue(mixed $value): string {
		if (is_int($value) || is_float($value)) {
			return (string)$value;
		}

		if (is_string($value) && is_numeric($value)) {
			return $value;
		}

		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		return '0';
	}
}

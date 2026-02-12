<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics;

/**
 * @phpstan-type MetricData array{value: mixed, label?: array<string, float|int|string>}
 * @phpstan-type Metric array{type: string, info: string, data: MetricData[], deprecated_use?: string}
 * @phpstan-type MetricDefinition array{name: string, type: string, description: string, deprecated_use?: string}
 */
final class MetricStore {

	/** @var array<string, Metric> */
	private array $metrics = [];

	/** @var array<string, MetricDefinition> */
	private array $definitions;

	/**
	 * @param array<string, MetricDefinition> $definitions
	 */
	public function __construct(array $definitions) {
		$this->definitions = $definitions;
	}

	/**
	 * @param array<string, float|int|string>|null $label
	 */
	public function addMapped(string $rawName, string|float|int $value, ?array $label = null): void {
		if (!isset($this->definitions[$rawName]['name'])) {
			return;
		}

		$value = $this->normalizeValue($rawName, $value);

		$finalName = $this->definitions[$rawName]['name'];

		if ($this->tryAddJsonStats($finalName, $rawName, $value, $label)) {
			$this->applyDefinition($finalName, $rawName);
			return;
		}

		if (str_contains($rawName, 'version')) {
			$this->metrics[$finalName]['data'][] = ['value' => 1, 'label' => ['version' => $value]];
			$this->applyDefinition($finalName, $rawName);
			return;
		}

		$metricData = ['value' => $value];
		if (isset($label)) {
			$metricData['label'] = $label;
		}
		$this->metrics[$finalName]['data'][] = $metricData;

		$this->applyDefinition($finalName, $rawName);
	}

	private function normalizeValue(string $rawName, string|float|int $value): string|float|int {
		if ($value === 'N/A') {
			return 0;
		}

		if ($rawName === 'killed_rate' || $rawName === 'mem_limit_rate') {
			if (!is_string($value)) {
				return (float)$value;
			}

			if (preg_match('/-?\\d+(?:\\.\\d+)?/', $value, $m) === 1) {
				return (float)$m[0];
			}

			return 0;
		}

		return $value;
	}

	/**
	 * @param array<string, float|int|string>|null $label
	 */
	private function tryAddJsonStats(
		string $finalName,
		string $rawName,
		string|float|int $value,
		?array $label
	): bool {
		if (!is_string($value)) {
			return false;
		}
		if (!str_contains($rawName, 'query_time_') && !str_contains($rawName, 'found_rows_')) {
			return false;
		}

		$row = json_decode($value, true);
		if (!is_array($row)) {
			return false;
		}

		foreach ($row as $k => $v) {
			if (!is_string($k)) {
				continue;
			}

			$metricData = ['value' => $v, 'label' => array_merge($label ?? [], ['type' => $k])];
			$this->metrics[$finalName]['data'][] = $metricData;
		}

		return true;
	}

	private function applyDefinition(string $finalName, string $rawName): void {
		$this->metrics[$finalName]['type'] = $this->definitions[$rawName]['type'];
		$this->metrics[$finalName]['info'] = $this->definitions[$rawName]['description'];
		$deprecatedUse = $this->definitions[$rawName]['deprecated_use'] ?? null;
		if (!is_string($deprecatedUse) || $deprecatedUse === '') {
			return;
		}

		$this->metrics[$finalName]['deprecated_use'] = $deprecatedUse;
	}

	/**
	 * @param array<string, string>|null $label
	 */
	public function addDirect(
		string $name,
		string $type,
		string $info,
		string|float|int $value,
		?array $label = null
	): void {
		$metricData = ['value' => $value];
		if (isset($label)) {
			$metricData['label'] = $label;
		}

		if (!isset($this->metrics[$name])) {
			$this->metrics[$name] = [
				'type' => $type,
				'info' => $info,
				'data' => [],
			];
		}

		$this->metrics[$name]['type'] = $type;
		$this->metrics[$name]['info'] = $info;
		$this->metrics[$name]['data'][] = $metricData;
	}

	/**
	 * @return array<string, Metric>
	 */
	public function all(): array {
		// Ensure we always expose all metric families (HELP/TYPE), even if no samples were collected.
		// This makes the /metrics output stable and allows snapshot/contract testing of metric names and TYPE.
		foreach ($this->definitions as $def) {
			$finalName = $def['name'];
			if ($finalName === '') {
				continue;
			}

			if (!isset($this->metrics[$finalName])) {
				$this->metrics[$finalName] = [
					'type' => $def['type'],
					'info' => $def['description'],
					'data' => [],
				];
			}

			$deprecatedUse = $def['deprecated_use'] ?? null;
			if (!is_string($deprecatedUse) || $deprecatedUse === '') {
				continue;
			}

			$this->metrics[$finalName]['deprecated_use'] = $deprecatedUse;
		}

		return $this->metrics;
	}

	public function has(string $name): bool {
		if (!isset($this->metrics[$name])) {
			return false;
		}

		return $this->metrics[$name]['data'] !== [];
	}
}

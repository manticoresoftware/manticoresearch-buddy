<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics\Collector;

use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricStore;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricsScrapeContext;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class SettingsCollector implements CollectorInterface {

	public function collect(Client $client, MetricStore $store, MetricsScrapeContext $context): void {
		unset($store);

		try {
			$request = $client->sendRequest('SHOW SETTINGS');
			if ($request->hasError()) {
				return;
			}

			$result = $request->getResult();
			if (!is_array($result[0])) {
				return;
			}

			$rows = $result[0]['data'] ?? null;
			if (!is_array($rows)) {
				return;
			}

			$out = [];
			foreach ($rows as $row) {
				$name = $row['Setting_name'] ?? null;
				$value = $row['Value'] ?? null;
				if (!is_string($name) || $name === '' || !is_string($value)) {
					continue;
				}

				$out[$name] = $value;
			}

			$context->settings = $out;
		} catch (\Throwable) {
			// Best-effort only; other collectors may fall back to defaults.
		}
	}
}

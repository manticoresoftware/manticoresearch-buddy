<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Plugin\Metrics;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;

final class RecordingMetricsClient extends Client {

	public static ?self $lastClone = null;
	public int $sendRequestCount = 0;
	public bool $forceSyncWasEnabled = false;

	/** @var array<int, string> */
	public array $queries = [];

	public function __construct(public string $name) {
	}

	public static function reset(): void {
		self::$lastClone = null;
	}

	public function __clone() {
		$this->name = 'clone';
		$this->sendRequestCount = 0;
		$this->queries = [];
		self::$lastClone = $this;
	}

	public function setForceSync(bool $value = true): static {
		$this->forceSyncWasEnabled = $value;
		return parent::setForceSync($value);
	}

	public function getSettings(): Settings {
		$settings = new Settings();
		$settings->searchdDataDir = '';
		$settings->searchdBinlogPath = '';
		$settings->searchdLog = '';
		return $settings;
	}

	public function sendRequest(
		string $request,
		?string $path = null,
		bool $disableAgentHeader = false,
		string $requestMethod = 'POST',
	): Response {
		unset($path, $disableAgentHeader, $requestMethod);

		++$this->sendRequestCount;
		$this->queries[] = $request;

		return Response::fromBody(json_encode([$this->responseFor($request)], JSON_THROW_ON_ERROR));
	}

	/**
	 * @return array{data: array<int, array<string, mixed>>, error: string, warning: string, total: int}
	 */
	private function responseFor(string $request): array {
		$data = match ($request) {
			'SHOW THREADS' => [
				['Name' => 'work_0', 'This/prev job time' => '0us', 'Info' => ''],
			],
			'SHOW STATUS' => [
				['Counter' => 'uptime', 'Value' => '1'],
				['Counter' => 'connections', 'Value' => '1'],
				['Counter' => 'version', 'Value' => 'Manticore 0.0.0 test'],
			],
			'SHOW TABLES' => [],
			default => [],
		};

		return [
			'data' => $data,
			'error' => '',
			'warning' => '',
			'total' => sizeof($data),
		];
	}
}

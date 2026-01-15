<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Ds\Vector;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class NodesInfoKibanaHandler extends BaseHandlerWithClient {

	use Traits\KibanaVersionTrait;

	/**
	 *  Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {

		$taskFn = static function (Client $manticoreClient): TaskResult {
			/** @var Settings $settings */
			$settings = $manticoreClient->getSettings();
			if (!isset($settings->searchdListen)) {
				throw new RuntimeException('Settings searchdListen parameter must be set');
			}

			[$ip, $port] = self::getNodeParameters($settings->searchdListen);

			return TaskResult::raw(
				[
					'nodes' => [
						'fWM984kTSbGjOAoF1qWQew' => [
							'http' => [
								'publish_address' => "$ip:$port",
							],
							'ip' => $ip,
							'version' => self::getKibanaVersion($manticoreClient),
						],
					],
				]
			);
		};

		return Task::create($taskFn, [$this->manticoreClient])->run();
	}

	/**
	 * @param Vector<string> $searchdListenSettings
	 * @return array{0:string,1:string}
	 * @throws \Exception
	 */
	protected static function getNodeParameters(Vector $searchdListenSettings): array {
		$httpListenTail = ':http';
		foreach ($searchdListenSettings as $line) {
			if (!str_ends_with($line, $httpListenTail)) {
				continue;
			}
			$line = str_replace($httpListenTail, '', $line);
			if (str_contains($line, ':')) {
				[$ip, $port] = explode(':', $line);
			} else {
				$ip = '127.0.0.1';
				$port = $line;
			}
			if ($ip === '0.0.0.0') {
				$hostname = gethostname();
				$ip = gethostbyname($hostname ?: '');
			}
			break;
		}
		if (!isset($ip, $port)) {
			throw new RuntimeException('Node parameters cannot be resolved');
		}

		return [$ip, $port];
	}
}

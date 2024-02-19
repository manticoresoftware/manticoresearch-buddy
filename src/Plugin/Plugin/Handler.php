<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\Plugin;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Plugin\BaseHandler;
use Manticoresearch\Buddy\Core\Plugin\Pluggable;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Strings;
use RuntimeException;

final class Handler extends BaseHandler {
  /** @var HTTPClient $manticoreClient */
	protected HTTPClient $manticoreClient;

	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

  /**
	 * Process the request
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload): TaskResult {
			$settings = $payload->getSettings();
			$pluggable = new Pluggable($settings);
			// We do switching against name just because there is strange trouble in threaded env
			switch ($payload->type->name) {
				// Install new plugin
				case 'Create':
					$package = (string)$payload->package;
					if ($payload->version) {
						$package .= ":{$payload->version}";
					}
					$pluggable->install($package);
					return TaskResult::none();

				// Delete installed plugin
				case 'Delete':
					$package = (string)$payload->package;
					$pluggable->remove($package);
					return TaskResult::none();

				// Show all installed plugins
				case 'Show':
					$rows = [];
					$plugins = static::getPlugins($pluggable);
					foreach ($plugins as $type => $list) {
						foreach ($list as $plugin) {
							$prefix = match ($type) {
								'core' => Pluggable::CORE_NS_PREFIX,
								default => Pluggable::EXTRA_NS_PREFIX,
							};
							/** @var array{short:string} $plugin */
							$pluginPrefix = $prefix . ucfirst(Strings::camelcaseBySeparator($plugin['short'], '-'));
							$className = $pluginPrefix . '\\Payload';
							/** @var array{full:string,short:string,version:string} $plugin */
							$rows[] = [
								'Package' => $plugin['full'],
								'Plugin' => $plugin['short'],
								'Version' => $plugin['version'],
								'Type' => $type,
								'Info' => $className::getInfo(),
							];
						}
					}

					return TaskResult::withData($rows)
						->column('Package', Column::String)
						->column('Plugin', Column::String)
						->column('Version', Column::String)
						->column('Type', Column::String)
						->column('Info', Column::String);
			}
		};

		// Define function to run on sucdessful execution
		$successFn = match ($this->payload->type) {
			ActionType::Create => fn() => static::processHook('installed'),
			ActionType::Delete => fn() => static::processHook('deleted'),
			ActionType::Show => fn() => null,
		};

		return Task::create(
			$taskFn, [$this->payload]
		)->on('success', $successFn)
		 ->run();
	}

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return ['manticoreClient'];
	}

	/**
	 * Instantiating the http client to execute requests to Manticore server
	 *
	 * @param HTTPClient $client
	 * $return HTTPClient
	 */
	public function setManticoreClient(HTTPClient $client): HTTPClient {
		$this->manticoreClient = $client;
		return $this->manticoreClient;
	}

	/**
	 * @param Pluggable $pluggable
	 * @return array{core:array<mixed>,local:array<mixed>,external:array<mixed>}
	 */
	protected static function getPlugins(Pluggable $pluggable): array {
		$externalPlugins = $pluggable->fetchExtraPlugins();
		$localPlugins = $pluggable->fetchLocalPlugins();
		$corePlugins = $pluggable->fetchCorePlugins();
		return [
			'core' => $corePlugins,
			'local' => $localPlugins,
			'external' => $externalPlugins,
		];
	}
}

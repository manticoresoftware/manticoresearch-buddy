<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\Plugin;

use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Plugin\Pluggable;
use Manticoresearch\Buddy\Core\Process\BaseProcessor;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\Strings;
use RuntimeException;

final class Handler extends BaseHandlerWithClient {
	/** @var Pluggable */
	protected Pluggable $pluggable;

	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Get props that we need to set to this handler on initialization
	 * @return array<string>
	 */
	public function getProps(): array {
		return [...parent::getProps(), 'pluggable'];
	}

	/**
	 * @param Pluggable $pluggable
	 * @return void
	 */
	public function setPluggable(Pluggable $pluggable): void {
		$this->pluggable = $pluggable;
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
			if (!$pluggable->isRegistered()) {
				throw new \RuntimeException('Plugins are not registered. Check your error log');
			}
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

				case 'Disable':
					return TaskResult::none();

				case 'Enable':
					return TaskResult::none();
			}
		};

		// Define function to run on sucdessful execution
		$successFn = match ($this->payload->type) {
			ActionType::Create => fn() => static::processEvent('installed'),
			ActionType::Delete => fn() => static::processEvent('deleted'),
			ActionType::Show => fn() => null,
			ActionType::Disable => fn() =>
				static::processEvent('disabled', [trim($this->payload->package ?? '', "'")]),
			ActionType::Enable => fn() =>
				static::processEvent('enabled', [trim($this->payload->package ?? '', "'")]),
		};

		return Task::create(
			$taskFn, [$this->payload]
		)->on('success', $successFn)
		 ->run();
	}

	/**
	 * @param Pluggable $pluggable
	 * @return array{core:array<mixed>,local:array<mixed>,external:array<mixed>}
	 */
	protected static function getPlugins(Pluggable $pluggable): array {
		$externalPlugins = $pluggable->getExtraPlugins();
		$localPlugins = $pluggable->getLocalPlugins();
		$corePlugins = $pluggable->getCorePlugins();
		return [
			'core' => $corePlugins,
			'local' => $localPlugins,
			'external' => $externalPlugins,
		];
	}

	/**
	 * @param string $event
	 * @param array<mixed> $args
	 * @return void
	 * @throws RuntimeException
	 */
	public function processEvent(string $event, array $args = []): void {
		$fn = match ($event) {
			// Happens when we installed the external plugin or deleted it
			'installed', 'deleted' => function () {
				$this->pluggable->getExtraPlugins(true);
			},
			// Happens when we disable the plugin
			'disabled' => function (string $name) {
				Buddy::debug("Plugin '$name' has been disabled");
				if (!$this->pluggable->disablePlugin($name)) {
					return;
				}

				static::pausePlugins([$name]);
			},
			// Happens when we enable the plugin
			'enabled' => function (string $name) {
				Buddy::debug("Plugin '$name' has been enabled");
				if (!$this->pluggable->enablePlugin($name)) {
					return;
				}

				static::resumePlugins([$name]);
			},
			default => throw new RuntimeException("Unknown hook event: $event"),
		};

		// Execute the choose function
		$fn(...$args);
	}

	/**
	 * Resume plugins after pause
	 * @param array<string> $filter
	 * @return void
	 */
	public function resumePlugins(array $filter = []): void {
		$this->pluggable->iterateProcessors(
			static function (BaseProcessor $processor) {
				$processor->execute('resume');
			}, $filter
		);
	}

	/**
	 * Run stop method of all plugin handlers
	 * @param array<string> $filter
	 * @return void
	 */
	public function pausePlugins(array $filter = []): void {
		$this->pluggable->iterateProcessors(
			static function (BaseProcessor $processor) {
				$processor->execute('pause');
			}, $filter
		);
	}
}

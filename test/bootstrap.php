<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

// TODO: do something to bootstrap tests
include_once __DIR__ . DIRECTORY_SEPARATOR
	. '..' . DIRECTORY_SEPARATOR
	. 'src' . DIRECTORY_SEPARATOR
	. 'init.php';

use Manticoresearch\Buddy\Base\Lib\MetricThread;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\Pluggable;
use Manticoresearch\Buddy\Core\Tool\ConfigManager;
use Psr\Container\ContainerInterface;

// Autoloader for test doubles
spl_autoload_register(
	function (string $className): void {
	// Handle test double classes with namespace
		if (str_contains($className, 'TestDoubles\\Testable')) {
			// Extract class name from namespace
			$classNameParts = explode('\\', $className);
			$shortClassName = end($classNameParts);
			$testDoubleFile = __DIR__ . '/Plugin/Sharding/TestDoubles/' . $shortClassName . '.php';
			if (file_exists($testDoubleFile)) {
				require_once $testDoubleFile;
				return;
			}
		}

	// Handle other test classes in the test directory
		$testFile = __DIR__ . '/' . str_replace('\\', '/', $className) . '.php';
		if (file_exists($testFile)) {
			require_once $testFile;
			return;
		}

		return;
	}
);

// Not the best way, but it's ok for now
// phpcs:disable
// we mock config file just to make tests pass because we do not test backup here
if (!is_dir('/etc/manticore')) {
	mkdir('/etc/manticore', 0755, true);
}
touch('/etc/manticore/manticore.conf');
putenv('SEARCHD_CONFIG=/etc/manticore/manticore.conf');
// Disable telemetry because we do not need it in tests
putenv('PLUGIN_DIR=/usr/local/lib/manticore');

ConfigManager::init();
ConfigManager::set('TELEMETRY', '0');

/** @var ContainerInterface $manticoreClient */
$container = Pluggable::getContainer();
/** @var Client $manticoreClient */
$manticoreClient = $container->get('manticoreClient');
$manticoreClient->setServerUrl('127.0.0.1:8312');
MetricThread::setContainer($container);
// phpcs:enable

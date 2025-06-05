<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Lib\CliArgsProcessor;
use Manticoresearch\Buddy\Base\Lib\ConfigManager;
use Manticoresearch\Buddy\Base\Lib\MetricThread;
use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\Loader;
use Manticoresearch\Buddy\Core\Cache\Flag as FlagCache;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Plugin\Pluggable;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\ExpressionLanguage\Expression;

// Init autoload first
include_once __DIR__ . DIRECTORY_SEPARATOR
	. '..' . DIRECTORY_SEPARATOR
	. 'vendor' . DIRECTORY_SEPARATOR
	. 'autoload.php'
;

// Set error reporting with excluding warnings
error_reporting(E_ALL & ~E_WARNING);

set_error_handler(buddy_error_handler(...)); // @phpstan-ignore-line
Buddy::setVersionFile(__DIR__ . '/../APP_VERSION');

// Initialize shared configuration manager before processing CLI arguments
// This allows CliArgsProcessor to set values directly in ConfigManager
try {
	ConfigManager::init();
} catch (Throwable $t) {
	Buddy::error($t, 'Failed to initialize ConfigManager');
	exit(1);
}

$opts = CliArgsProcessor::run();
$authToken = getenv('BUDDY_TOKEN') ?: null;
// Reset token
putenv('BUDDY_TOKEN=');

// Build container dependencies
// TODO: probably it's a good idea to get rid out of this container at all
// TODO: And at least think about extraction of plugin dependencies
$container = new ContainerBuilder();
$container->register('QueryParserLoader', Loader::class);
$container
	->register('manticoreClient', HTTPClient::class)
	->addArgument($opts['listen'])
	->addArgument($authToken);
$container->register('flagCache', FlagCache::class);

$container
	->register('pluggable', Pluggable::class)
	->setArguments([new Expression('service("manticoreClient").getSettings()')]);

ConfigManager::set('LISTEN', $opts['listen']);
$plugins = [
	'manticoresoftware/buddy-plugin-empty-string',
	'manticoresoftware/buddy-plugin-backup',
	'manticoresoftware/buddy-plugin-emulate-elastic',
	'manticoresoftware/buddy-plugin-fuzzy',
	'manticoresoftware/buddy-plugin-create-table',
	'manticoresoftware/buddy-plugin-create-cluster',
	'manticoresoftware/buddy-plugin-drop',
	'manticoresoftware/buddy-plugin-insert',
	'manticoresoftware/buddy-plugin-alias',
	'manticoresoftware/buddy-plugin-select',
	'manticoresoftware/buddy-plugin-show',
	'manticoresoftware/buddy-plugin-plugin',
	'manticoresoftware/buddy-plugin-test',
	'manticoresoftware/buddy-plugin-alter-column',
	'manticoresoftware/buddy-plugin-alter-distributed-table',
	'manticoresoftware/buddy-plugin-alter-rename-table',
	'manticoresoftware/buddy-plugin-modify-table',
	'manticoresoftware/buddy-plugin-knn',
	'manticoresoftware/buddy-plugin-replace',
	'manticoresoftware/buddy-plugin-queue',
	'manticoresoftware/buddy-plugin-sharding',
	'manticoresoftware/buddy-plugin-update',
	'manticoresoftware/buddy-plugin-autocomplete',
	'manticoresoftware/buddy-plugin-cli-table',
	'manticoresoftware/buddy-plugin-distributed-insert',
	'manticoresoftware/buddy-plugin-truncate',
	'manticoresoftware/buddy-plugin-metrics',
];
// Filtering out the plugins that we don't need
$plugins = array_filter(
	$plugins,
	fn ($plugin) => !in_array($plugin, $opts['skip'])
);
Pluggable::setContainer($container);
Pluggable::setCorePlugins($plugins);
MetricThread::setContainer($container);

return $container;

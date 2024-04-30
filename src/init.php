<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Lib\CliArgsProcessor;
use Manticoresearch\Buddy\Base\Lib\MetricThread;
use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\Loader;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\Pluggable;
use Manticoresearch\Buddy\Core\Plugin\TableFormatter;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

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

$opts = CliArgsProcessor::run();

// Build container dependencies
// TODO: probably it's a good idea to get rid out of this container at all
// TODO: And at least think about extraction of plugin dependencies
$container = new ContainerBuilder();
$container->register('ManticoreResponseBuilder', Response::class);
$container->register('QueryParserLoader', Loader::class);
$container
	->register('manticoreClient', HTTPClient::class)
	->addArgument(new Reference('ManticoreResponseBuilder'))
	->addArgument($opts['listen']);
$container->register('tableFormatter', TableFormatter::class);

putenv("LISTEN={$opts['listen']}");
Pluggable::setContainer($container);
Pluggable::setCorePlugins(
	[
	'manticoresoftware/buddy-plugin-empty-string',
	'manticoresoftware/buddy-plugin-backup',
	'manticoresoftware/buddy-plugin-emulate-elastic',
	'manticoresoftware/buddy-plugin-create',
	'manticoresoftware/buddy-plugin-insert',
	'manticoresoftware/buddy-plugin-alias',
	'manticoresoftware/buddy-plugin-select',
	'manticoresoftware/buddy-plugin-show',
	'manticoresoftware/buddy-plugin-cli-table',
	'manticoresoftware/buddy-plugin-plugin',
	'manticoresoftware/buddy-plugin-test',
	'manticoresoftware/buddy-plugin-modify-table',
	'manticoresoftware/buddy-plugin-knn',
	'manticoresoftware/buddy-plugin-replace',
	'manticoresoftware/buddy-plugin-queue',
	'manticoresoftware/buddy-plugin-sharding',
	]
);
MetricThread::setContainer($container);

return $container;

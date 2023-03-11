<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Lib\MetricThread;
use Manticoresearch\Buddy\Base\Lib\QueryProcessor;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\TableFormatter;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Plugin\Insert\QueryParser\Loader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

// Init autoload first
include_once __DIR__ . DIRECTORY_SEPARATOR
	. '..' . DIRECTORY_SEPARATOR
	. 'vendor' . DIRECTORY_SEPARATOR
	. 'autoload.php'
;

set_error_handler(buddy_error_handler(...)); // @phpstan-ignore-line
Buddy::setVersionFile(__DIR__ . '/../APP_VERSION');

// Build container dependencies
// TODO: probably it's a good idea to get rid out of this container at all
// TODO: And at least think about extraction of plugin dependencies
$container = new ContainerBuilder();
$container->register('ManticoreResponseBuilder', Response::class);
$container->register('QueryParserLoader', Loader::class);
$container
	->register('manticoreClient', HTTPClient::class)
	->addArgument(new Reference('ManticoreResponseBuilder'))
	->addArgument('127.0.0.1:9308');
$container->register('tableFormatter', TableFormatter::class);

QueryProcessor::setContainer($container);
MetricThread::setContainer($container);

Task::init(__DIR__ . DIRECTORY_SEPARATOR . 'runtime.php');

return $container;

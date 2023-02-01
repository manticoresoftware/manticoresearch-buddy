<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Lib\MetricThread;
use Manticoresearch\Buddy\Lib\QueryProcessor;
use Manticoresearch\Buddy\Lib\TableFormatter;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use Manticoresearch\Buddy\Network\ManticoreClient\Response;
use Manticoresearch\Buddy\QueryParser\Loader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

// Init autoload first
include_once __DIR__ . DIRECTORY_SEPARATOR
	. '..' . DIRECTORY_SEPARATOR
	. 'vendor' . DIRECTORY_SEPARATOR
	. 'autoload.php'
;

set_error_handler(buddy_error_handler(...)); // @phpstan-ignore-line

// Build container dependencies
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

return $container;

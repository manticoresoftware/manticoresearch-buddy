<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Lib\ManticoreHTTPClient;
use Manticoresearch\Buddy\Lib\ManticoreResponseBuilder;
use Manticoresearch\Buddy\Lib\QueryParserLoader;
use Manticoresearch\Buddy\Lib\QueryProcessor;
use Symfony\Component\DependencyInjection\ContainerBuilder as Container;
use Symfony\Component\DependencyInjection\Reference;

// Init autoload first
include_once __DIR__ . DIRECTORY_SEPARATOR
	. '..' . DIRECTORY_SEPARATOR
	. 'vendor' . DIRECTORY_SEPARATOR
	. 'autoload.php'
;
// Build container dependencies
$container = new Container();
$container->register('ManticoreResponseBuilder', ManticoreResponseBuilder::class);
$container->register('QueryParserLoader', QueryParserLoader::class);
$container
	->register('manticoreClient', ManticoreHTTPClient::class)
	->addArgument(new Reference('ManticoreResponseBuilder'))
	->addArgument('127.0.0.1:9308');

QueryProcessor::setContainer($container);

return $container;

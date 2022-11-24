<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder as Container;
use Symfony\Component\DependencyInjection\Reference;

final class ContainerBuilder {

	/**
	 * @return ContainerInterface
	 */
	public static function create(): ContainerInterface {
		$container = new Container();
		$container->register('ManticoreResponseBuilder', ManticoreResponseBuilder::class);
		$container->register('QueryParserLoader', QueryParserLoader::class);
		$container->register('ManticoreStatement', ManticoreStatement::class);
		$container
			->register('ManticoreHTTPClient', ManticoreHTTPClient::class)
			->addArgument(new Reference('ManticoreResponseBuilder'))
			->addArgument(ErrorQueryExecutor::DEFAULT_SERVER_URL);
		$container
			->register('ErrorQueryRequest', ErrorQueryRequest::class)
			->addArgument(null)
			->addArgument(new Reference('QueryParserLoader'))
			->addArgument(new Reference('ManticoreStatement'));
		$container
			->register('ErrorQueryExecutor', ErrorQueryExecutor::class)
			->addMethodCall('setManticoreClient', [new Reference('ManticoreHTTPClient')]);

		return $container;
	}
}

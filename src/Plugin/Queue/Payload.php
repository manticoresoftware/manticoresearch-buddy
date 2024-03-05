<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Exception;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;
use Manticoresearch\Buddy\Core\Plugin\Pluggable;
use Manticoresearch\Buddy\Core\Tool\SqlQueryParser;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 */
final class Payload extends BasePayload
{
	const TYPE_SOURCE = 'source';
	const TYPE_VIEW = 'view';

	public static string $type;
	public static string $sourceType;

	public Endpoint $endpointBundle;

	public array $parsedPayload = [];

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();

		$self->endpointBundle = $request->endpointBundle;

		if ($self->endpointBundle === Endpoint::Sql) {
			$self->parsedPayload = static::$sqlQueryParser::getParsedPayload();

			if (isset($self->parsedPayload['SOURCE'])) {
				$self::$type = self::TYPE_SOURCE;
			} elseif (isset($self->parsedPayload['VIEW'])) {
				$self::$type = self::TYPE_VIEW;
			}
		}


		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {

		// CREATE SOURCE/ MATERIALIZED VIEW
		// TODO done this later
		// SHOW SOURCES/VIEWS
		// DROP SOURCE/VIEW
		// ALTER SOURCE/VIEW


		/*
		 *
		 * create source kafka (id bigint, term text, abbrev text, GlossDef json) type='kafka' broker_list='kafka1:9092' topic_list='myTopic' consumer_group='manticore' num_consumers='1';
		 * {

		 */
		$parsedPayload = static::$sqlQueryParser::getParsedPayload();

		return (isset($parsedPayload['SOURCE']) || isset($parsedPayload['VIEW']));
	}


	/**
	 * @return string
	 * @throws Exception
	 */
	public function getHandlerClassName(): string {
		return __NAMESPACE__ . '\\' . match (static::$type) {
				self::TYPE_SOURCE => self::parseSourceType(
					static::$sqlQueryParser::getParsedPayload()['SOURCE']['options']
				),
				self::TYPE_VIEW => 'ViewHandler',
				default => throw new Exception('Cannot find handler for request type: ' . static::$type),
		};
	}

	public static function parseSourceType(array $options): string {
		foreach ($options as $option) {
			if (isset($option['sub_tree'][0]['base_expr'])
				&& $option['sub_tree'][0]['base_expr'] === 'type') {
				return match (SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr'])) {
					'kafka' => 'SourceHandlers\\KafkaWorker'
				};
			}
		}
		throw new Exception('Cannot find handler for request type: ' . static::$type);
	}

	/**
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public static function getProcessors(): array {
		/** @var Client $client */
		$client = Pluggable::getContainer()->get('manticoreClient');
		return [
			(new QueueProcess)->setClient($client),
		];
	}
}

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
use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source\BaseCreateSourceHandler;
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
	const TYPE_SOURCES = 'sources';
	const TYPE_VIEWS = 'views';

  const REQUEST_TYPE_GET = 'get';
	const REQUEST_TYPE_VIEW = 'view';
	const REQUEST_TYPE_CREATE = 'create';
	const REQUEST_TYPE_EDIT = 'alter';
	const REQUEST_TYPE_DELETE = 'drop';



	public static string $type;
	public static string $sourceType;

	public Endpoint $endpointBundle;

	public string $originQuery = '';
	public array $parsedPayload = [];

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();

		$self->endpointBundle = $request->endpointBundle;

		if ($self->endpointBundle === Endpoint::Sql) {
			$self->originQuery = $request->payload;
			$self->parsedPayload = static::$sqlQueryParser::getParsedPayload();

			if (isset($self->parsedPayload['SOURCE'])) {
				$self::$type = self::REQUEST_TYPE_CREATE.self::TYPE_SOURCE;
			} elseif (isset($self->parsedPayload['VIEW'])) {
				$self::$type = self::REQUEST_TYPE_CREATE.self::TYPE_VIEW;
			} elseif (isset($self->parsedPayload['SHOW'][0]['base_expr'])
				&& in_array($self->parsedPayload['SHOW'][0]['base_expr'], ['sources', 'views'])) {
				switch ($self->parsedPayload['SHOW'][0]['base_expr']) {
					case self::TYPE_SOURCES:
						$self::$type = self::REQUEST_TYPE_VIEW.self::TYPE_SOURCES;
						break;
					case self::TYPE_VIEWS:
						$self::$type = self::REQUEST_TYPE_VIEW.self::TYPE_VIEWS;
						break;
				}
			} elseif (isset($self->parsedPayload['SHOW'][1]['expr_type'])) {
				switch ($self->parsedPayload['SHOW'][1]['expr_type']) {
					case self::TYPE_SOURCE:
						$self::$type = self::REQUEST_TYPE_GET.self::TYPE_SOURCES;
						break;
					case self::TYPE_VIEW:
						$self::$type = self::REQUEST_TYPE_GET.self::TYPE_VIEWS;
						break;
				}
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
		// SHOW CREATE SOURCE / MATERIALIZED VIEW
		// DROP SOURCE/VIEW
		// ALTER SOURCE/VIEW


		/*
		 *
		 * CREATE SOURCE kafka (id bigint, term text, abbrev text, GlossDef json) type='kafka'
		   broker_list='kafka:9092' topic_list='my-data' consumer_group='manticore' num_consumers='4' batch=50;

		 * CREATE TABLE destination_kafka (id bigint, name text, short_name text, received_at text, size multi);

		 * CREATE MATERIALIZED VIEW view_table TO destination_kafka AS SELECT id, term as name,
		   abbrev as short_name, UTC_TIMESTAMP() as received_at, GlossDef.size as size FROM kafka;

		 */

		$parsedPayload = static::$sqlQueryParser::getParsedPayload();

		return (
			isset($parsedPayload['SOURCE']) ||
			isset($parsedPayload['VIEW']) ||
			(isset($parsedPayload['SHOW'][0]['base_expr']) &&
				in_array($parsedPayload['SHOW'][0]['base_expr'], ['sources', 'views'])) ||
			(isset($parsedPayload['SHOW'][1]['expr_type']) &&
				in_array($parsedPayload['SHOW'][1]['expr_type'], ['source', 'view']))
		);
	}


	/**
	 * @return string
	 * @throws Exception
	 */
	public function getHandlerClassName(): string {
		return __NAMESPACE__ . '\\' . match (static::$type) {
				self::REQUEST_TYPE_CREATE.self::TYPE_SOURCE => self::parseSourceType(
					static::$sqlQueryParser::getParsedPayload()['SOURCE']['options']
				),
				self::REQUEST_TYPE_CREATE.self::TYPE_VIEW => 'Handlers\\View\\CreateViewHandler',


				self::REQUEST_TYPE_VIEW.self::TYPE_SOURCES => 'Handlers\\Source\\ViewSourceHandler',
				self::REQUEST_TYPE_VIEW.self::TYPE_VIEWS => 'Handlers\\View\\ViewViewsHandler',

				self::REQUEST_TYPE_GET.self::TYPE_SOURCES => 'Handlers\\Source\\GetSourceHandler',
				self::REQUEST_TYPE_GET.self::TYPE_VIEWS => 'Handlers\\View\\GetViewHandler',

				default => throw new Exception('Cannot find handler for request type: ' . static::$type),
		};
	}

	public static function parseSourceType(array $options): string {
		foreach ($options as $option) {
			if (isset($option['sub_tree'][0]['base_expr'])
				&& $option['sub_tree'][0]['base_expr'] === 'type') {
				return match (SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr'])) {
					'kafka' => 'Handlers\\Source\\CreateKafka'
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

		if ($client->hasTable(BaseCreateSourceHandler::SOURCE_TABLE_NAME)) {
			return [(new QueueProcess)->setClient($client)];
		}
		return [];
	}
}

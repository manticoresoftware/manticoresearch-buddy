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
use Manticoresearch\Buddy\Core\Error\GenericError;
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

	const TYPE_MATERLIALIZED = 'materialized';

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
	 * @throws GenericError
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();

		$self->endpointBundle = $request->endpointBundle;

		if ($self->endpointBundle === Endpoint::Sql) {
			$self->originQuery = $request->payload;
			$self->parsedPayload = static::$sqlQueryParser::getParsedPayload();


			if (isset($self->parsedPayload['SOURCE'])) {
				$self::$type = self::REQUEST_TYPE_CREATE . self::TYPE_SOURCE;
			} elseif (isset($self->parsedPayload['VIEW'])) {
				$self::$type = self::REQUEST_TYPE_CREATE . self::TYPE_MATERLIALIZED . self::TYPE_VIEW;
			} elseif (self::isViewSourcesMatch($self->parsedPayload)) {
				$self::$type = self::REQUEST_TYPE_VIEW . self::TYPE_SOURCES;
			} elseif (self::isViewMaterializedViewsMatch($self->parsedPayload)) {
				$self::$type = self::REQUEST_TYPE_VIEW . self::TYPE_MATERLIALIZED . self::TYPE_VIEWS;
			} elseif (self::isGetSourceMatch($self->parsedPayload)) {
				$self::$type = self::REQUEST_TYPE_GET . self::TYPE_SOURCES;
			} elseif (self::isGetMaterializedViewMatch($self->parsedPayload)) {
				$self::$type = self::REQUEST_TYPE_GET . self::TYPE_MATERLIALIZED . self::TYPE_VIEWS;
			} elseif (self::isDropSourceMatch($self->parsedPayload)) {
				$self::$type = self::REQUEST_TYPE_DELETE . self::TYPE_SOURCE;
			} else {
				throw GenericError::create("Can't detect payload type. Payload: " . json_encode($self->parsedPayload));
			}
		}

		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {

		/**
		 * @example
		 *
		 * CREATE SOURCE kafka (id bigint, term text, abbrev text, GlossDef json) type='kafka'
		   broker_list='kafka:9092' topic_list='my-data' consumer_group='manticore' num_consumers='4' batch=50;

		 * CREATE TABLE destination_kafka (id bigint, name text, short_name text, received_at text, size multi);

		 * CREATE MATERIALIZED VIEW view_table TO destination_kafka AS SELECT id, term as name,
		   abbrev as short_name, UTC_TIMESTAMP() as received_at, GlossDef.size as size FROM kafka;

		 */

		$parsedPayload = static::$sqlQueryParser::getParsedPayload();

		echo json_encode($parsedPayload) . "\n";

		// TODO Alter should synchronize views and source (at least count of records to count of buffer tables)
		// TODO Alter should run worker
		// TODO Alter should stop worker
		// TODO Alter suspend=0 should be blocked if source not exist
		// TODO Finish drop source and view
		// TODO finish alter

		return (
			isset($parsedPayload['SOURCE']) ||
			isset($parsedPayload['VIEW']) ||
			self::isViewSourcesMatch($parsedPayload) ||
			self::isViewMaterializedViewsMatch($parsedPayload) ||
			self::isGetSourceMatch($parsedPayload) ||
			self::isGetMaterializedViewMatch($parsedPayload) ||
			self::isDropSourceMatch($parsedPayload)
		);
	}


	/**
	 * Should match SHOW MATERIALIZED VIEW
	 *
	 * @param array $parsedPayload
	 * @return bool
	 * @example {"SHOW":[{"expr_type":"reserved","base_expr":"materialized"},{"expr_type":"reserved","base_expr":"views"}]}
	 */
	private static function isViewMaterializedViewsMatch(array $parsedPayload): bool {
		return (isset($parsedPayload['SHOW'][0]['base_expr']) &&
			isset($parsedPayload['SHOW'][1]['base_expr']) &&
			$parsedPayload['SHOW'][0]['base_expr'] === self::TYPE_MATERLIALIZED &&
			$parsedPayload['SHOW'][1]['base_expr'] === self::TYPE_VIEWS);
	}

	/**
	 * Should match SHOW MATERIALIZED VIEW {name}
	 *
	 * @param array $parsedPayload
	 * @return bool
	 * @example {"SHOW":[{"expr_type":"reserved","base_expr":"materialized"},{"expr_type":"reserved","base_expr":"view"},
	 * {"expr_type":"view","view":"view_table","no_quotes":{"delim":false,"parts":["view_table"]},
	 * "base_expr":"view_table"}]}
	 */
	private static function isGetMaterializedViewMatch(array $parsedPayload): bool {
		return (isset($parsedPayload['SHOW'][0]['base_expr']) &&
			isset($parsedPayload['SHOW'][1]['base_expr']) &&
			!empty($parsedPayload['SHOW'][2]['no_quotes']['parts'][0]) &&
			$parsedPayload['SHOW'][0]['base_expr'] === self::TYPE_MATERLIALIZED &&
			$parsedPayload['SHOW'][1]['base_expr'] === self::TYPE_VIEW);
	}


	/**
	 * Should match SHOW SOURCES
	 *
	 * @param array $parsedPayload
	 * @return bool
	 * @example {"SHOW":[{"expr_type":"reserved","base_expr":"sources"}]}
	 */
	private static function isViewSourcesMatch(array $parsedPayload): bool {
		return (isset($parsedPayload['SHOW'][0]['base_expr']) &&
			$parsedPayload['SHOW'][0]['base_expr'] === self::TYPE_SOURCES);
	}

	/**
	 * Should match SHOW SOURCE {name}
	 *
	 * @param array $parsedPayload
	 * @return bool
	 * @example {"SHOW":[{"expr_type":"reserved","base_expr":"source"},
	 * {"expr_type":"source","source":"kafka","no_quotes":{"delim":false,"parts":["kafka"]},"base_expr":"kafka"}]}
	 */
	private static function isGetSourceMatch(array $parsedPayload): bool {
		return (
			isset($parsedPayload['SHOW'][0]['base_expr']) &&
			$parsedPayload['SHOW'][0]['base_expr'] === self::TYPE_SOURCE &&
			!empty($parsedPayload['SHOW'][1]['no_quotes']['parts'][0])
		);
	}

	/**
	 * Should match DROP SOURCE {name}
	 *
	 * @param array $parsedPayload
	 * @return bool
	 * @example {"DROP":{"expr_type":"source","option":false,"if-exists":false,
	 * "sub_tree":[{"expr_type":"reserved","base_expr":"source"},
	 * {"expr_type":"expression","base_expr":"kafka","sub_tree":[{"expr_type":"source","table":"kafka","no_quotes":
	 * {"delim":false,"parts":["kafka"]},"alias":false,"base_expr":"kafka","delim":false}]}]}}
	 */
	private static function isDropSourceMatch(array $parsedPayload): bool {
		return (
			isset($parsedPayload['DROP']['expr_type']) &&
			isset($parsedPayload['DROP']['sub_tree'][1]['sub_tree'][0]['no_quotes']['parts'][0]) &&
			$parsedPayload['DROP']['expr_type'] === self::TYPE_SOURCE
		);
	}

	/**
	 * Should match ALTER MATERIALIZED VIEW {name} suspended=0;
	 *
	 * @param array $parsedPayload
	 * @return bool
	 *
	 * @example {"ALTER":{"base_expr":"materialized view","sub_tree":[]},"VIEW":{"base_expr":"view_table",
	 * "name":"view_table","no_quotes":{"delim":false,"parts":["view_table"]},"create-def":false,
	 * "options":[{"expr_type":"expression","base_expr":"suspended=0","delim":" ","sub_tree":[{"expr_type":
	 * "reserved","base_expr":"suspended"},{"expr_type":"operator","base_expr":"="},
	 * {"expr_type":"const","base_expr":"0"}]}]}}
	 */
	private static function isAlterMaterializedViewMatch(array $parsedPayload): bool {
		return false;
	}

	/**
	 * @return string
	 * @throws Exception
	 */
	public function getHandlerClassName(): string {
		return __NAMESPACE__ . '\\' . match (static::$type) {
				self::REQUEST_TYPE_CREATE . self::TYPE_SOURCE => self::parseSourceType(
					static::$sqlQueryParser::getParsedPayload()['SOURCE']['options']
				),
				self::REQUEST_TYPE_CREATE . self::TYPE_MATERLIALIZED . self::TYPE_VIEW => 'Handlers\\View\\CreateViewHandler',
				self::REQUEST_TYPE_VIEW . self::TYPE_SOURCES => 'Handlers\\Source\\ViewSourceHandler',
				self::REQUEST_TYPE_VIEW . self::TYPE_MATERLIALIZED . self::TYPE_VIEWS => 'Handlers\\View\\ViewViewsHandler',
				self::REQUEST_TYPE_GET . self::TYPE_SOURCES => 'Handlers\\Source\\GetSourceHandler',
				self::REQUEST_TYPE_GET . self::TYPE_MATERLIALIZED . self::TYPE_VIEWS => 'Handlers\\View\\GetViewHandler',
				self::REQUEST_TYPE_DELETE . self::TYPE_SOURCE => 'Handlers\\Source\\DropSourceHandler',
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
			return [QueueProcess::getInstance()->setClient($client)];
		}
		return [];
	}
}

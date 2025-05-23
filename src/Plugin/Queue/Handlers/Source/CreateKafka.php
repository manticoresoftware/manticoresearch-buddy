<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source;

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\View\CreateViewHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\QueryValidationError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\SqlQueryParser;
use PHPSQLParser\PHPSQLParser;

/**
 * @extends BaseCreateSourceHandler<array{
 *        CREATE: array{
 *            expr_type: string,
 *            not-exists: bool,
 *            base_expr: string,
 *            sub_tree: array{
 *                expr_type: string,
 *                base_expr: string
 *            }[]
 *        },
 *        SOURCE: array{
 *            base_expr: string,
 *            name: string,
 *            no_quotes?: array{
 *                delim: bool,
 *                parts: string[]
 *            },
 *            create-def: array{
 *                expr_type: string,
 *                base_expr: string,
 *                sub_tree: array{
 *                    expr_type: string,
 *                    base_expr: string,
 *                    sub_tree: array{
 *                        expr_type: string,
 *                        base_expr: string,
 *                        sub_tree: array{
 *                            expr_type: string,
 *                            base_expr: string
 *                        }[]
 *                    }[]
 *                }[]
 *            },
 *            options: array{
 *                expr_type: string,
 *                base_expr: string,
 *                delim: string,
 *                sub_tree: array{
 *                    expr_type: string,
 *                    base_expr: string,
 *                    delim: string,
 *                    sub_tree: array{
 *                        expr_type: string,
 *                        base_expr: string
 *                    }[]
 *                }[]
 *            }[]
 *        }
 *    }>
 */
final class CreateKafka extends BaseCreateSourceHandler {
	/**
	 * @param Payload<array{
	 *         CREATE: array{
	 *             expr_type: string,
	 *             not-exists: bool,
	 *             base_expr: string,
	 *             sub_tree: array{
	 *                 expr_type: string,
	 *                 base_expr: string
	 *             }[]
	 *         },
	 *         SOURCE: array{
	 *             base_expr: string,
	 *             name: string,
	 *             no_quotes?: array{
	 *                 delim: bool,
	 *                 parts: string[]
	 *             },
	 *             create-def: array{
	 *                 expr_type: string,
	 *                 base_expr: string,
	 *                 sub_tree: array{
	 *                     expr_type: string,
	 *                     base_expr: string,
	 *                     sub_tree: array{
	 *                         expr_type: string,
	 *                         base_expr: string,
	 *                         sub_tree: array{
	 *                             expr_type: string,
	 *                             base_expr: string
	 *                         }[]
	 *                     }[]
	 *                 }[]
	 *             },
	 *             options: array{
	 *                 expr_type: string,
	 *                 base_expr: string,
	 *                 delim: string,
	 *                 sub_tree: array{
	 *                     expr_type: string,
	 *                     base_expr: string,
	 *                     delim: string,
	 *                     sub_tree: array{
	 *                         expr_type: string,
	 *                         base_expr: string
	 *                     }[]
	 *                 }[]
	 *             }[]
	 *         }
	 *     }> $payload
	 * @throws ManticoreSearchClientError|\PHPSQLParser\exceptions\UnsupportedFeatureException
	 */
	public static function handle(Payload $payload, Client $manticoreClient): TaskResult {


		$options = self::parseOptions($payload);

		if (!empty($options->partitionList) && $options->numConsumers > 1) {
			throw ManticoreSearchClientError::create(
				"You can't create multiple consumers when specifying a partition. ".
				'In this case, num_consumers must be set to 1.'
			);
		}

		$sql = /** @lang ManticoreSearch */
			'SELECT * FROM ' . Payload::SOURCE_TABLE_NAME .
			" WHERE match('@name \"" . $options->name . "\"')";


		$record = $manticoreClient->sendRequest($sql)->getResult();
		if (is_array($record[0]) && $record[0]['total']) {
			throw ManticoreSearchClientError::create("Source $options->name already exist");
		}

		for ($i = 0; $i < $options->numConsumers; $i++) {
			$attrs = json_encode(
				[
					'broker' => $options->brokerList,
					'topic' => $options->topicList,
					'group' => $options->consumerGroup ?? 'manticore',
					'partitions' => $options->partitionList ?? [],
					'batch' => $options->batch ?? '100',
				]
			);

			$bufferTablePrefix = Payload::BUFFER_TABLE_PREFIX;
			/** @l $query */
			$query = /** @lang ManticoreSearch */
				"CREATE TABLE {$bufferTablePrefix}{$options->name}_{$i} $options->schema";

			$request = $manticoreClient->sendRequest($query);
			if ($request->hasError()) {
				throw ManticoreSearchClientError::create((string)$request->getError());
			}

			$escapedPayload = str_replace("'", "\\'", $payload->originQuery);
			$customMapping = str_replace("'", "\\'", $options->customMapping);

			$query = /** @lang ManticoreSearch */
				'INSERT INTO ' . Payload::SOURCE_TABLE_NAME .
				' (id, type, name, full_name, buffer_table, attrs, custom_mapping, original_query) VALUES ' .
				"(0, '" . self::SOURCE_TYPE_KAFKA . "', '$options->name','{$options->name}_$i'," .
				"'{$bufferTablePrefix}{$options->name}_$i', '$attrs', '$customMapping', '$escapedPayload')";

			$request = $manticoreClient->sendRequest($query);
			if ($request->hasError()) {
				throw ManticoreSearchClientError::create((string)$request->getError());
			}
		}

		self::handleOrphanViews($options->name, $options->numConsumers, $manticoreClient);

		return TaskResult::none();
	}

	/**
	 * @throws ManticoreSearchClientError|\PHPSQLParser\exceptions\UnsupportedFeatureException
	 */
	public static function handleOrphanViews(string $sourceName, int $maxIndex, Client $client): void {
		$viewsTable = Payload::VIEWS_TABLE_NAME;
		if (!$client->hasTable($viewsTable)) {
			return;
		}

		$sql = /** @lang Manticore */
			"SELECT * FROM $viewsTable " .
			"WHERE MATCH('@source_name \"" . $sourceName . "_*\"') AND suspended=1";

		$request = $client->sendRequest($sql);
		if ($request->hasError()) {
			throw ManticoreSearchClientError::create((string)$request->getError());
		}

		$result = $request->getResult();

		if (!is_array($result[0])) {
			return;
		}

		$views = $result[0]['data'];
		$viewsCount = sizeof($views);


		if ($viewsCount > 0 && $viewsCount < $maxIndex) {
			$originalQuery = (string)$views[0]['original_query'];
			$viewName = (string)$views[0]['name'];

			$parser = new PHPSQLParser($originalQuery);
			$destinationTableName = $parser->parsed['VIEW']['to']['no_quotes']['parts'][0];
			unset($parser->parsed['CREATE'], $parser->parsed['VIEW']);

			CreateViewHandler::createViewRecords(
				$client, $viewName, $parser->parsed,
				$sourceName, $originalQuery, $destinationTableName,
				$maxIndex, $viewsCount, 1
			);
		} else {
			$ids = self::getOrphanIds($views, $sourceName, $maxIndex);

			if ($ids === []) {
				return;
			}

			$ids = implode(',', $ids);
			Buddy::debug("Remove orphan views records ids ($ids)");
			$sql = /** @lang manticore */
				"DELETE FROM $viewsTable WHERE id in ($ids)";
			$rawResult = $client->sendRequest($sql);
			if ($rawResult->hasError()) {
				throw ManticoreSearchClientError::create((string)$rawResult->getError());
			}
		}
	}

	/**
	 * @param array<int, array<string>> $views
	 * @param string $sourceName
	 * @param int $maxIndex
	 * @return array<string>
	 */
	public static function getOrphanIds(array $views, string $sourceName, int $maxIndex): array {
		$ids = [];
		foreach ($views as $orphanView) {
			$viewSourceName = explode('_', $orphanView['source_name']);
			// remove index from array, leave only name
			$index = array_pop($viewSourceName);
			$viewSourceName = implode('_', $viewSourceName);
			if ($viewSourceName !== $sourceName || $index < $maxIndex) {
				continue;
			}

			$ids[] = $orphanView['id'];
		}

		return $ids;
	}

	/**
	 * @param Payload<array{
	 *         CREATE: array{
	 *             expr_type: string,
	 *             not-exists: bool,
	 *             base_expr: string,
	 *             sub_tree: array{
	 *                 expr_type: string,
	 *                 base_expr: string
	 *             }[]
	 *         },
	 *         SOURCE: array{
	 *             base_expr: string,
	 *             name: string,
	 *             no_quotes?: array{
	 *                 delim: bool,
	 *                 parts: string[]
	 *             },
	 *             create-def: array{
	 *                 expr_type: string,
	 *                 base_expr: string,
	 *                 sub_tree: array{
	 *                     expr_type: string,
	 *                     base_expr: string,
	 *                     sub_tree: array{
	 *                         expr_type: string,
	 *                         base_expr: string,
	 *                         sub_tree: array{
	 *                             expr_type: string,
	 *                             base_expr: string
	 *                         }[]
	 *                     }[]
	 *                 }[]
	 *             },
	 *             options: array{
	 *                 expr_type: string,
	 *                 base_expr: string,
	 *                 delim: string,
	 *                 sub_tree: array{
	 *                     expr_type: string,
	 *                     base_expr: string,
	 *                     delim: string,
	 *                     sub_tree: array{
	 *                         expr_type: string,
	 *                         base_expr: string
	 *                     }[]
	 *                 }[]
	 *             }[]
	 *         }
	 *     }> $payload
	 * @return \stdClass
	 */
	public static function parseOptions(Payload $payload): \stdClass {
		$result = new \stdClass();

		$parsedPayload = $payload->model->getPayload();
		$result->name = strtolower($parsedPayload['SOURCE']['name']);

		$mapping = self::parseMapping($parsedPayload['SOURCE']['create-def']['sub_tree']);
		$result->customMapping = $mapping['customMapping'];
		$result->schema = $mapping['schema'];

		foreach ($parsedPayload['SOURCE']['options'] as $option) {
			if (!isset($option['sub_tree'][0]['base_expr'])) {
				continue;
			}

			match (strtolower($option['sub_tree'][0]['base_expr'])) {
				'broker_list' =>
				$result->brokerList = SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr']),
				'topic_list' =>
				$result->topicList = SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr']),
				'consumer_group' =>
				$result->consumerGroup = SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr']),
				'num_consumers' =>
				$result->numConsumers = (int)SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr']),
				'batch' =>
				$result->batch = (int)SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr']),
				'partition_list' =>
				$result->partitionList = self::parsePartitions($option['sub_tree'][2]['base_expr']),
				default => ''
			};
		}
		return $result;
	}

	/**
	 * @param string $option
	 *
	 * @return array<int>
	 * @throws GenericError
	 */
	private static function parsePartitions(string $option): array {

		$partitions = explode(',', SqlQueryParser::removeQuotes($option));
		$partitions = array_map('intval', $partitions);
		foreach ($partitions as $partition) {
			if (is_int($partition) && $partition >= 0) {
				continue;
			}

			GenericError::throw("Invalid partition value: $partition");
		}
		return $partitions;
	}

	/**
   * @param array{
	 *                      expr_type: string,
	 *                      base_expr: string,
	 *                      sub_tree: array{
	 *                          expr_type: string,
	 *                          base_expr: string,
	 *                          sub_tree: array{
	 *                              expr_type: string,
	 *                              base_expr: string
	 *                          }[]
	 *                      }[]
	 *                  }[] $fields
   *
   * @return array{customMapping: non-empty-string|false, schema:non-falsy-string}
	 * @throws GenericError
*/
	public static function parseMapping(array $fields): array {
		$schema = [];
		$customMapping = [];
		$pattern = '/^`?([a-zA-Z0-9_]+)`?\s*[\'"]+(.*)[\'"]+\s([a-zA-Z]+)$/usi';

		foreach ($fields as $field) {
			$definition = strtolower($field['base_expr']);

			if (preg_match($pattern, $definition, $matches)) {
				$schema[] = $matches[1].' '.$matches[3];
				$customMapping[$matches[1]] = $matches[2];
				continue;
			}

			$schema[] = $definition	;
		}

		$encodedMapping = json_encode($customMapping);
		if ($encodedMapping === false) {
			QueryValidationError::throw('Incorrect custom mapping provided');
		}
		return [
			'customMapping' => $encodedMapping,
			'schema' => '('.implode(',', $schema).')',
		];
	}
}

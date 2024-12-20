<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\CreateTableHandler as Handler;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\Payload;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class CreateTableHandlerTest extends TestCase {

	use TestProtectedTrait;

	public function testColumnExpressionFromElasticQueryOk():void {
		$columnInfo = [
			'location' => [
				'type' => 'geo_point',
			],
			'title' => [
				'type' => 'text',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
						'ignore_above' => 256,
					],
				],
			],
			'blob' => [
				'type' => 'binary',
			],
			'name' => [
				'type' => 'text',
			],
			'some_boolean' => [
				'type' => 'boolean',
			],
			'some_completion' => [
				'type' => 'completion',
			],
			'some_date' => [
				'type' => 'date',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
						'ignore_above' => 256,
					],
				],
			],
			'some_date_nanos' => [
				'type' => 'date_nanos',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
						'ignore_above' => 256,
					],
				],
			],
			'some_dense_vector' => [
				'type' => 'dense_vector',
				'dims' => 3,
			],
			'some_flattened' => [
				'type' => 'flattened',
			],
			'some_geo_point' => [
				'type' => 'geo_point',
			],
			'some_geo_shape' => [
				'type' => 'geo_point',
			],
			'some_histogram' => [
				'type' => 'histogram',
			],
			'ip_addr' => [
				'type' => 'ip',
			],
			'some_object' => [
				'properties' => [
					'user' => [
						'type' => 'text',
						'fields' => [
							'keyword' => [
								'type' => 'keyword',
								'ignore_above' => 256,
							],
						],
					],
				],
			],
			'long_numeric' => [
				'type' => 'long',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
					],
				],
			],
			'integer_numeric' => [
				'type' => 'integer',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
					],
				],
			],
			'short_numeric' => [
				'type' => 'short',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
					],
				],
			],
			'byte_numeric' => [
				'type' => 'byte',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
					],
				],
			],
			'float_numeric' => [
				'type' => 'float',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
					],
				],
			],
			'half_float_numeric' => [
				'type' => 'half_float',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
					],
				],
			],
			'scaled_float_numeric' => [
				'type' => 'scaled_float',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
					],
				],
			],
			'unsigned_long_numeric' => [
				'type' => 'unsigned_long',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
					],
				],
			],
			'some_point' => [
				'type' => 'point',
			],
			'some_integer_range' => [
				'type' => 'integer_range',
			],
			'some_float_range' => [
				'type' => 'float_range',
			],
			'some_long_range' => [
				'type' => 'long_range',
			],
			'some_date_range' => [
				'type' => 'date_range',
			],
			'some_ip_range' => [
				'type' => 'ip_range',
			],
			'text_search_as_you_type' => [
				'type' => 'search_as_you_type',
			],
			'some_shape' => [
				'type' => 'shape',
			],
			'some_match_only_text' => [
				'type' => 'match_only_text',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
						'ignore_above' => 256,
					],
				],
			],
			'release' => [
				'type' => 'version',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
						'ignore_above' => 256,
					],
				],
			],
			// Opensearch-specific types
			'some_keyword' => [
				'type' => 'keyword',
			],
			'flat_object' => [
				'type' => 'flat_object',
			],
			'knn_vector_with_settings' => [
				'type' => 'knn_vector',
				'dimension' => 2,
				'method' => [
					'name' => 'hnsw',
					'space_type' => 'innerproduct',
					'engine' => 'nmslib',
					'parameters' => [
						'm' => 24,
						'ef_construction' => 128,
					],
				],
			],
			'knn_vector_by_default' => [
				'type' => 'knn_vector',
				'dimension' => 3,
			],
		];
		$columnExpression = '`location` json,`title` string indexed attribute,`blob` string,`name` text,'
			. '`some_boolean` bool,`some_completion` string,`some_date` timestamp,`some_date_nanos` bigint,'
			. '`some_dense_vector` json,`some_flattened` json,`some_geo_point` json,`some_geo_shape` json,'
			. '`some_histogram` json,`ip_addr` string,`some_object` json,`long_numeric` bigint,`integer_numeric` int,'
			. '`short_numeric` int,`byte_numeric` int,`float_numeric` float,`half_float_numeric` float,'
			. '`scaled_float_numeric` float,`unsigned_long_numeric` int,`some_point` json,`some_integer_range` json,'
			. '`some_float_range` json,`some_long_range` json,`some_date_range` json,`some_ip_range` json,'
			. '`text_search_as_you_type` text,`some_shape` json,`some_match_only_text` string indexed attribute,'
			. '`release` string,`some_keyword` string,`flat_object` json,`knn_vector_with_settings` '
			. "float_vector knn_type='hnsw' knn_dims='2' hnsw_similarity='ip' hnsw_m='24' hnsw_ef_construction='128',"
			. "`knn_vector_by_default` float_vector knn_type='hnsw' knn_dims='3' hnsw_similarity='l2' hnsw_m='16' "
			. "hnsw_ef_construction='100'";
		$payload = new Payload();
		// Use a dummy Payload here just to initilaize a handler instance
		$handler = new Handler($payload);
		$res = self::invokeMethod($handler, 'buildColumnExpr', [$columnInfo]);
		$this->assertEquals($columnExpression, $res);
	}

	public function testColumnExpressionFromElasticQueryFail():void {
		$testingSet = [
			'alias' => [
				'some_alias' => [
					'type' => 'alias',
				],
			],
			'join' => [
				'some_join' => [
					'type' => 'join',
				],
			],
			'double' => [
				'some_double' => [
					'type' => 'double',
				],
			],
			'percolator' => [
				'some_percolator' => [
					'type' => 'percolator',
				],
			],
			'double_range' => [
				'some_double_range' => [
					'type' => 'double_range',
				],
			],
			'rank_feature' => [
				'some_rank_feature' => [
					'type' => 'rank_feature',
				],
			],
			// Opensearch-specific types
			'nested' => [
				'some_nested' => [
					'type' => 'nested',
				],
			],
			'token_count' => [
				'some_token_count' => [
					'type' => 'token_count',
				],
			],
			'rank_features' => [
				'some_rank_features' => [
					'type' => 'rank_features',
				],
			],
		];

		$payload = new Payload();
		// Use a dummy Payload here just to initilaize a handler instance
		$handler = new Handler($payload);
		foreach ($testingSet as $dataType => $columnInfo) {
			try {
				self::invokeMethod($handler, 'buildColumnExpr', [$columnInfo]);
			} catch (Exception $e) {
				$this->assertEquals(
					"Unsupported data type $dataType found in the data schema",
					$e->getMessage()
				);
			}
		}

		$columnInfo = [
			'test' => [],
		];
		try {
			self::invokeMethod($handler, 'buildColumnExpr', [$columnInfo]);
		} catch (Exception $e) {
			$this->assertEquals(
				'Data type not found for column test',
				$e->getMessage()
			);
		}
	}

	public function testKnnTypeExpressionFromOpensearchQueryFail():void {
		$columnInfoSet = [
			[
				'knn_vector' => [
					'type' => 'knn_vector',
					'dimension' => 2,
					'method' => [
						'name' => 'hnsw',
						'space_type' => 'l2',
						'engine' => 'lucene',
						'parameters' => [
							'ef_construction' => 128,
							'm' => 24,
						],
					],
				],
			],
			[
				'knn_vector' => [
					'type' => 'knn_vector',
					'dimension' => 3,
					'method' => [
						'name' => 'hnsw',
						'space_type' => 'l1',
						'engine' => 'nmslib',
						'parameters' => [
							'm' => 24,
						],
					],
				],
			],
		];
		$payload = new Payload();
		$handler = new Handler($payload);
		foreach ($columnInfoSet as $columnInfo) {
			try {
				self::invokeMethod($handler, 'buildColumnExpr', [$columnInfo]);
			} catch (Exception $e) {
				$this->assertEquals(
					'Unsupported settings for `knn_vector` type found in the data schema',
					$e->getMessage()
				);
			}
		}
	}
}

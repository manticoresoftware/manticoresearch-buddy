<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Core\Error\QueryParseError;

final class ModelConfigValidator {
	/**
	 * Supported flat fields for CREATE RAG MODEL.
	 *
	 * @var array<int, string>
	 */
	private const array MODEL_FIELDS = [
		'model',
		'description',
		'style_prompt',
		'api_key',
		'base_url',
		'timeout',
		'retrieval_limit',
		'max_document_length',
	];

	/**
	 * @param array{identifier:string, model: string, description?: string, style_prompt?: string,
	 *   api_key?: string, base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
	 *   max_document_length?: string|int} $config
	 *
	 * @return array{name: string, model: string, description?: string, style_prompt?: string,
	 *   api_key?: string, base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
	 *   max_document_length?: string|int}
	 * @throws QueryParseError
	 */
	public function validate(array $config): array {
		$this->validateSupportedFields($config);
		$this->validateRequiredFields($config);
		$this->validateModelId($config);
		$this->validateTimeout($config);
		$this->validateRetrievalLimit($config);
		$this->validateMaxDocumentLength($config);

		$createConfig = ['name' => $config['identifier']];
		foreach (self::MODEL_FIELDS as $field) {
			if (!array_key_exists($field, $config)) {
				continue;
			}

			$createConfig[$field] = $config[$field];
		}

		/** @var array{name: string, model: string, description?: string, style_prompt?: string,
		 *   api_key?: string, base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
		 *   max_document_length?: string|int} $createConfig
		 */
		return $createConfig;
	}

	/**
	 * @param array<string, mixed> $config
	 *
	 * @return void
	 * @throws QueryParseError
	 */
	private function validateSupportedFields(array $config): void {
		$supportedFields = array_flip(self::MODEL_FIELDS);
		foreach (array_keys($config) as $field) {
			if ($field === 'identifier') {
				continue;
			}

			if (!isset($supportedFields[$field])) {
				throw QueryParseError::create("Unsupported field '$field'");
			}
		}
	}

	/**
	 * @param array{identifier:string, model: string, description?: string, style_prompt?: string,
	 *   api_key?: string, base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
	 *   max_document_length?: string|int} $config
	 *
	 * @return void
	 * @throws QueryParseError
	 */
	private function validateRequiredFields(array $config): void {
		if (empty($config['model'])) {
			throw QueryParseError::create("Required field 'model' is missing or empty");
		}
	}

	/**
	 * @param array{identifier:string, model: string, description?: string, style_prompt?: string,
	 *   api_key?: string, base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
	 *   max_document_length?: string|int} $config
	 *
	 * @return void
	 * @throws QueryParseError
	 */
	private function validateModelId(array $config): void {
		$modelId = trim($config['model']);
		[$provider, $model] = array_pad(explode(':', $modelId, 2), 2, '');
		if ($provider === '' || $model === '') {
			throw QueryParseError::create("model must use 'provider:model' format");
		}
	}

	/**
	 * @param array{identifier:string, model: string, description?: string, style_prompt?: string,
	 *   api_key?: string, base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
	 *   max_document_length?: string|int} $config
	 *
	 * @return void
	 * @throws QueryParseError
	 */
	private function validateTimeout(array $config): void {
		if (!isset($config['timeout'])) {
			return;
		}

		$timeout = $config['timeout'];
		if (is_string($timeout)) {
			if (preg_match('/^[0-9]+\z/', $timeout) !== 1) {
				throw QueryParseError::create('timeout must be an integer between 1 and 65536');
			}

			$timeout = (int)$timeout;
		}

		if (!is_int($timeout) || $timeout < 1 || $timeout > 65536) {
			throw QueryParseError::create('timeout must be an integer between 1 and 65536');
		}
	}

	/**
	 * @param array{identifier:string, model: string, description?: string, style_prompt?: string,
	 *   api_key?: string, base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
	 *   max_document_length?: string|int} $config
	 *
	 * @return void
	 * @throws QueryParseError
	 */
	private function validateRetrievalLimit(array $config): void {
		if (!isset($config['retrieval_limit'])) {
			return;
		}

		$k = $config['retrieval_limit'];
		if (is_string($k)) {
			if (preg_match('/^[0-9]+\z/', $k) !== 1) {
				throw QueryParseError::create('retrieval_limit must be an integer between 1 and 50');
			}

			$k = (int)$k;
		}

		if (!is_int($k) || $k < 1 || $k > 50) {
			throw QueryParseError::create('retrieval_limit must be an integer between 1 and 50');
		}
	}

	/**
	 * @param array{identifier:string, model: string, description?: string, style_prompt?: string,
	 *   api_key?: string, base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
	 *   max_document_length?: string|int} $config
	 *
	 * @return void
	 * @throws QueryParseError
	 */
	private function validateMaxDocumentLength(array $config): void {
		if (!isset($config['max_document_length'])) {
			return;
		}

		$maxDocumentLength = $config['max_document_length'];
		if (is_string($maxDocumentLength)) {
			if (preg_match('/^-?[0-9]+\z/', $maxDocumentLength) !== 1) {
				throw QueryParseError::create(
					'max_document_length must be 0 or an integer between 100 and 65536'
				);
			}

			$maxDocumentLength = (int)$maxDocumentLength;
		}

		if (!is_int($maxDocumentLength)
			|| ($maxDocumentLength !== 0 && ($maxDocumentLength < 100 || $maxDocumentLength > 65536))
		) {
			throw QueryParseError::create(
				'max_document_length must be 0 or an integer between 100 and 65536'
			);
		}
	}
}

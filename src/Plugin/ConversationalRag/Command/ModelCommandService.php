<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Command;

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ModelConfigValidator;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ModelManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Payload;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Random\RandomException;

final class ModelCommandService {
	public function __construct(
		private readonly ModelManager $modelManager,
		private readonly Client $client
	) {
	}

	/**
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError|RandomException
	 */
	public function create(Payload $payload): TaskResult {
		/** @var array{identifier: string, model: string, description?: string, style_prompt?: string,
		 *   api_key?: string, base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
		 *   max_document_length?: string|int} $config
		 */
		$config = $payload->params;
		$createConfig = (new ModelConfigValidator())->validate($config);
		$uuid = $this->modelManager->createModel($this->client, $createConfig);

		return TaskResult::withRow(['uuid' => $uuid])
			->column('uuid', Column::String);
	}

	/**
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function show(): TaskResult {
		$models = $this->modelManager->getAllModels($this->client);

		$data = [];
		foreach ($models as $model) {
			$data[] = [
				'uuid' => $model['uuid'],
				'name' => $model['name'],
				'model' => $model['model'],
				'created_at' => $model['created_at'],
			];
		}

		return TaskResult::withData($data)
			->column('uuid', Column::String)
			->column('name', Column::String)
			->column('model', Column::String)
			->column('created_at', Column::String);
	}

	/**
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function describe(Payload $payload): TaskResult {
		$modelNameOrUuid = $payload->params['model_name_or_uuid'];
		$model = $this->modelManager->getModelByUuidOrName($this->client, $modelNameOrUuid);

		$data = [];
		foreach ($model as $key => $value) {
			if (!is_array($value)) {
				$data[] = [
					'property' => $key,
					'value' => (string)$value,
				];
				continue;
			}

			/** @var array<string, scalar> $value */
			foreach ($value as $setting => $settingValue) {
				if ($setting === 'api_key') {
					$settingValue = 'HIDDEN';
				}

				$data[] = [
					'property' => "settings.$setting",
					'value' => (string)$settingValue,
				];
			}
		}

		return TaskResult::withData($data)
			->column('property', Column::String)
			->column('value', Column::String);
	}

	/**
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function drop(Payload $payload): TaskResult {
		$modelNameOrUuid = $payload->params['model_name_or_uuid'];
		$ifExists = ($payload->params['if_exists'] ?? '') === '1';

		$this->modelManager->deleteModelByUuidOrName(
			$this->client,
			$modelNameOrUuid,
			$ifExists
		);

		return TaskResult::none();
	}
}

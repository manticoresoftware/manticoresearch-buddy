<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Command\ConversationCommandService;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Command\ModelCommandService;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationAnswerGenerator;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationResearchAssistant;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationSearchFlow;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationSearchRouter;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationSearchWithResearchFlow;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationSearchWithResearchRouter;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class Handler extends BaseHandlerWithClient {
	public const float RESPONSE_TEMPERATURE = 0.1;

	private ?LlmProvider $llmProvider;

	public function __construct(public Payload $payload, ?LlmProvider $llmProvider = null) {
		$this->llmProvider = $llmProvider;
	}

	public function run(): Task {
		$taskFn = static function (
			Payload $payload,
			Client $client,
			?LlmProvider $injectedProvider
		): TaskResult {
			$modelManager = new ModelManager();
			$conversationManager = new ConversationManager($client);
			self::initializeTables($modelManager, $conversationManager, $client);

			$modelCommands = new ModelCommandService($modelManager, $client);
			$conversationCommand = self::createConversationCommand(
				$modelManager,
				$conversationManager,
				$client,
				$injectedProvider ?? new LlmProvider()
			);

			return match ($payload->action) {
				Payload::ACTION_CREATE_MODEL => $modelCommands->create($payload),
				Payload::ACTION_SHOW_MODELS => $modelCommands->show(),
				Payload::ACTION_DESCRIBE_MODEL => $modelCommands->describe($payload),
				Payload::ACTION_DROP_MODEL => $modelCommands->drop($payload),
				Payload::ACTION_CONVERSATION => $conversationCommand->handle($payload),
				default => throw QueryParseError::create("Unknown action: $payload->action")
			};
		};

		return Task::create($taskFn, [$this->payload, $this->manticoreClient, $this->llmProvider])->run();
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	private static function initializeTables(
		ModelManager $modelManager,
		ConversationManager $conversationManager,
		Client $client
	): void {
		$modelManager->initializeTables($client);
		$conversationManager->initializeTable($client);
	}

	private static function createConversationCommand(
		ModelManager $modelManager,
		ConversationManager $conversationManager,
		Client $client,
		LlmProvider $provider
	): ConversationCommandService {
		return new ConversationCommandService(
			$modelManager,
			$provider,
			$conversationManager,
			new ConversationSearchFlow(new ConversationSearchRouter()),
			new ConversationSearchWithResearchFlow(
				new ConversationSearchWithResearchRouter(),
				new ConversationResearchAssistant()
			),
			new ConversationAnswerGenerator(),
			new SearchEngine($client),
			$client
		);
	}
}

<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)
 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationHistory;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRoute;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRouter;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\LlmProvider;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use PHPUnit\Framework\TestCase;

final class ConversationRouterTest extends TestCase {
	public function testRewritesCurrentFollowUpUsingPreviousAnswer(): void {
		$provider = $this->createMock(LlmProvider::class);
		$model = ['model' => 'openai:gpt-4'];
		$provider->expects($this->once())->method('configure')->with($model);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn (string $prompt): bool => str_contains($prompt, '{"user":"tv shows?"}')
				&& str_contains($prompt, 'Game of Thrones: fantasy drama')
				&& str_contains($prompt, 'what is the cast in GoT?')
				&& str_contains(
					$prompt, 'using the latest message\'s subject and only earlier constraints that still apply'
				)
			),
			$this->callback(
				static fn (array $tool): bool => $tool['name'] === 'route_conversation'
				&& array_keys($tool['parameters']['properties']) ===
				['route', 'standalone_question', 'exclude_query', 'reason']
				&& $tool['parameters']['properties']['route']['enum'] ===
				[ConversationRoute::SEARCH, ConversationRoute::CHAT]
				&& $tool['parameters']['required'] === ['route', 'standalone_question', 'exclude_query', 'reason']
			),
			['temperature' => 0.1, 'max_tokens' => 1024]
		)->willReturn($this->toolResponse('What is the cast of Game of Thrones?'));

		$route = (new ConversationRouter())->route(
			'what is the cast in GoT?', $this->history(), $provider, $model
		);
		$this->assertInstanceOf(ConversationRoute::class, $route);
		$this->assertSame(ConversationRoute::SEARCH, $route->route);
		$this->assertSame('What is the cast of Game of Thrones?', $route->standaloneQuestion);
	}

	public function testPreferenceAndPriorQuestionProduceNewSearch(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn (string $prompt): bool => str_contains($prompt, 'More shows like Game of Thrones')
				&& str_contains($prompt, 'Game of Thrones: fantasy drama')
			)
		)->willReturn($this->toolResponse('Find shows like Game of Thrones'));
		$result = (new ConversationRouter())->route(
			'More shows like Game of Thrones', $this->history(), $provider, ['model' => 'openai:gpt-4']
		);
		$this->assertSame('Find shows like Game of Thrones', $result->standaloneQuestion);
	}

	public function testNamedExclusionIsSeparateFromPositiveQuestion(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->method('generateToolCall')->willReturn(
			$this->toolResponse('What are some good TV shows to watch?', 'Breaking Bad')
		);
		$result = (new ConversationRouter())->route(
			'I already saw Breaking Bad', $this->history(), $provider, ['model' => 'openai:gpt-4']
		);
		$this->assertSame('What are some good TV shows to watch?', $result->standaloneQuestion);
		$this->assertSame('Breaking Bad', $result->excludeQuery);
	}

	public function testNegativePreferenceAfterRecommendationStillGeneratesPositiveQuestionAndNamedExclusion(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn (string $prompt): bool => str_contains($prompt, 'I don\'t like Stranger Things')
				&& str_contains($prompt, 'tv shows?')
				&& str_contains($prompt, 'negative preferences and bare result rejections')
			)
		)->willReturn($this->toolResponse('Recommend TV shows I might enjoy', 'Stranger Things'));
		$result = (new ConversationRouter())->route(
			"I don't like Stranger Things", $this->history(), $provider, ['model' => 'openai:gpt-4']
		);
		$this->assertSame('Recommend TV shows I might enjoy', $result->standaloneQuestion);
		$this->assertSame('Stranger Things', $result->excludeQuery);
	}

	public function testExplicitCategoryExclusionIsExtractedWithoutRequiringAnEntityName(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn (string $prompt): bool => str_contains(
					$prompt, 'Put explicitly excluded subjects only in exclude_query'
				)
					&& str_contains($prompt, 'I do not like horror')
			)
		)->willReturn($this->toolResponse('Recommend TV shows I might enjoy', 'horror'));
		$result = (new ConversationRouter())->route(
			'I do not like horror', $this->history(), $provider, ['model' => 'openai:gpt-4']
		);
		$this->assertSame('Recommend TV shows I might enjoy', $result->standaloneQuestion);
		$this->assertSame('horror', $result->excludeQuery);
	}

	public function testUnspecifiedRejectionStillGeneratesSearchQuestion(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->method('generateToolCall')->willReturn($this->toolResponse('Find alternative TV shows to watch'));
		$result = (new ConversationRouter())->route(
			'No, not these.', $this->history(), $provider, ['model' => 'openai:gpt-4']
		);
		$this->assertSame('Find alternative TV shows to watch', $result->standaloneQuestion);
	}

	public function testHybridCanCarryApplicableAudienceAcrossCategoryChange(): void {
		$history = new ConversationHistory([['role' => 'user', 'message' => 'I need ladies top']]);
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn (string $prompt): bool => str_contains(
					$prompt, 'Do not discard compatible qualifiers when the subject changes'
				)
				&& str_contains($prompt, 'preserve the subject and its stated constraints across turns')
				&& str_contains($prompt, 'I need vest')
			),
			$this->callback(
				static fn (array $tool): bool => $tool['parameters']['required'] ===
				['route', 'standalone_question', 'exclude_query', 'reason', 'lexical_terms']
				&& $tool['parameters']['properties']['lexical_terms']['maxItems'] === 4
			)
		)->willReturn($this->toolResponse('What ladies vests are available?', '', ['vest', 'ladies']));
		$result = (new ConversationRouter())->route(
			'I need vest', $history, $provider, ['model' => 'openai:gpt-4'], 'hybrid'
		);
		$this->assertSame('What ladies vests are available?', $result->standaloneQuestion);
		$this->assertSame(['vest', 'ladies'], $result->lexicalTerms);
	}

	public function testUniversalSearchPromptRetainsGenericRulesWithoutEcommerceTuning(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn (string $prompt): bool => str_contains(
					$prompt, 'Retain explicit constraints only while they apply to the current subject'
				)
				&& str_contains($prompt, 'Keep entity names and type terms separate unless the user gave an exact name')
				&& str_contains($prompt, 'Put explicitly excluded subjects only in exclude_query')
				&& str_contains($prompt, 'For CHAT, lexical_terms must be empty')
				&& !str_contains($prompt, 'For product catalogs')
				&& !str_contains($prompt, 'Keep alternatives separate rather than combining them into an exact phrase')
				&& !str_contains($prompt, 'For product searches')
				&& !str_contains($prompt, 'A correction rejecting the wrong product or category')
				&& !str_contains($prompt, 'ANSWER_FROM_HISTORY')
				&& !str_contains($prompt, 'For REJECT')
			)
		)->willReturn($this->toolResponse('Find ladies vests', '', ['vest', 'ladies']));
		$route = (new ConversationRouter())->route(
			'I need vest', new ConversationHistory([]), $provider, [], 'hybrid'
		);
		$this->assertSame(ConversationRoute::SEARCH, $route->route);
	}

	public function testHybridKeepsCompatibleQualifiersWithoutCastingNewSubjectAsOldSubtype(): void {
		$history = new ConversationHistory(
			[['role' => 'user', 'message' => 'Find French-language articles about HTTP cache headers']]
		);
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn (string $prompt): bool => str_contains($prompt, 'do not cast the new subject as a subtype')
				&& str_contains($prompt, 'Do not discard compatible qualifiers')
			)
		)->willReturn(
			$this->toolResponse(
				'Find French-language articles about TLS handshake failures', '', ['TLS handshake', 'failures']
			)
		);
		$result = (new ConversationRouter())->route(
			'I need TLS handshake failures instead', $history, $provider, ['model' => 'openai:gpt-4'], 'hybrid'
		);
		$this->assertSame('Find French-language articles about TLS handshake failures', $result->standaloneQuestion);
		$this->assertSame(['TLS handshake', 'failures'], $result->lexicalTerms);
	}

	public function testHybridKeepsBrandProductTypeAndConstraintsAcrossTurns(): void {
		$history = new ConversationHistory(
			[['role' => 'user', 'message' => 'Find Under Armour Flex Pants in size 12']]
		);
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn (string $prompt): bool => str_contains(
					$prompt, 'Retain explicit constraints only while they apply'
				)
				&& str_contains($prompt, 'Do not discard compatible qualifiers when the subject changes')
			)
		)->willReturn(
			$this->toolResponse(
				'Find black size 12 Under Armour Flex Pants', '', ['Under Armour', 'Flex Pants', 'size 12', 'black']
			)
		);
		$result = (new ConversationRouter())->route(
			'Only in black', $history, $provider, ['model' => 'openai:gpt-4'], 'hybrid'
		);
		$this->assertSame(['Under Armour', 'Flex Pants', 'size 12', 'black'], $result->lexicalTerms);
	}

	public function testHybridNamedOptionRetainsPreviouslyDescribedProduct(): void {
		$history = new ConversationHistory([['role' => 'user', 'message' => 'Find a red striped raincoat']]);
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn (string $prompt): bool => str_contains(
					$prompt, 'Resolve references to the same subject using the conversation history'
				)
				&& str_contains(
					$prompt, 'using the latest message\'s subject and only earlier constraints that still apply'
				)
			)
		)->willReturn(
			$this->toolResponse(
				'Is the red striped Northstar raincoat available in size M?', '',
				['Northstar', 'raincoat', 'striped', 'size M']
			)
		);
		$result = (new ConversationRouter())->route(
			'I like the Northstar option in M', $history, $provider, ['model' => 'openai:gpt-4'], 'hybrid'
		);
		$this->assertSame('Is the red striped Northstar raincoat available in size M?', $result->standaloneQuestion);
	}

	public function testHybridMustReturnNonEmptyLexicalTerms(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(3))->method('generateToolCall')->willReturn(
			$this->toolResponse('Find a frame', '', [])
		);
		$this->expectException(ManticoreSearchClientError::class);
		(new ConversationRouter())->route(
			'Find a frame', new ConversationHistory([]), $provider, [], 'hybrid'
		);
	}

	public function testHybridMissingLexicalTermsFailsWithoutQuestionFallback(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(3))->method('generateToolCall')->willReturn(
			$this->toolResponse('Find a frame')
		);
		try {
			(new ConversationRouter())->route(
				'Find a frame', new ConversationHistory([]), $provider, [], 'hybrid'
			);
			$this->fail('Expected missing lexical terms to fail');
		} catch (ManticoreSearchClientError $error) {
			$this->assertStringContainsString('requires lexical terms', $error->getResponseError());
		}
	}

	public function testKnnDoesNotRequireOrReturnLexicalTerms(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->willReturn(
			$this->toolResponse('Find a frame', '', ['unexpected'])
		);
		$result = (new ConversationRouter())->route(
			'Find a frame', new ConversationHistory([]), $provider, []
		);
		$this->assertSame([], $result->lexicalTerms);
	}

	public function testHybridFollowUpUsesPreviousAnswerForCurrentSearch(): void {
		$history = new ConversationHistory(
			[
			['role' => 'user', 'message' => 'Find the protocol'],
			['role' => 'assistant', 'message' => 'The HTTP RFC 9110 document describes status 429.'],
			]
		);
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn (string $prompt): bool => str_contains($prompt, 'Find the protocol')
				&& str_contains($prompt, 'HTTP RFC 9110 document describes status 429')
				&& str_contains($prompt, 'Which status was that?')
			)
		)->willReturn(
			$this->toolResponse(
				'Which status does the HTTP RFC 9110 document describe?', '', ['RFC 9110', 'status 429']
			)
		);
		$result = (new ConversationRouter())->route(
			'Which status was that?', $history, $provider, [], 'hybrid'
		);
		$this->assertSame('Which status does the HTTP RFC 9110 document describe?', $result->standaloneQuestion);
		$this->assertSame(['RFC 9110', 'status 429'], $result->lexicalTerms);
	}

	public function testTransientProviderFailureRetries(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(2))->method('generateToolCall')->willReturnOnConsecutiveCalls(
			['success' => false, 'error' => 'LLM tool call failed', 'content' => '', 'provider' => 'llm'],
			$this->toolResponse('What are denim jackets with buttons instead of zippers?')
		);
		$result = (new ConversationRouter())->route(
			'I want denim jackets with buttons instead of a zipper', new ConversationHistory([]), $provider, []
		);
		$this->assertSame('What are denim jackets with buttons instead of zippers?', $result->standaloneQuestion);
	}

	public function testHybridAcceptsOverlappingLexicalTerms(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->willReturn(
			$this->toolResponse('Find raincoats', '', ['coat', 'raincoat'])
		);
		$route = (new ConversationRouter())->route(
			'Find raincoats', new ConversationHistory([]), $provider, [], 'hybrid'
		);
		$this->assertSame(['coat', 'raincoat'], $route->lexicalTerms);
	}

	public function testHybridDoesNotImposeAnArbitraryLexicalTermLength(): void {
		$term = str_repeat('x', 121);
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->willReturn(
			$this->toolResponse('Find the document', '', [$term])
		);
		$route = (new ConversationRouter())->route(
			'Find the document', new ConversationHistory([]), $provider, [], 'hybrid'
		);
		$this->assertSame([$term], $route->lexicalTerms);
	}

	public function testHybridValidationRetryPrependsCorrectionBeforeOriginalPrompt(): void {
		$prompts = [];
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(2))->method('generateToolCall')->willReturnCallback(
			function (string $prompt) use (&$prompts): array {
				$prompts[] = $prompt;
				return sizeof($prompts) === 1
					? $this->toolResponse('Find a frame', '', ['frame', 'color', 'size', 'brand', 'material'])
					: $this->toolResponse('Find a frame', '', ['frame', 'color']);
			}
		);
		$result = (new ConversationRouter())->route(
			'Find a frame', new ConversationHistory([]), $provider, [], 'hybrid'
		);
		$this->assertSame(['frame', 'color'], $result->lexicalTerms);
		$this->assertStringStartsWith('Previous tool call failed validation.', $prompts[1]);
		$this->assertStringContainsString('SEARCH requires 1 to 4 non-empty lexical_terms', $prompts[1]);
		$this->assertStringEndsWith($prompts[0], $prompts[1]);
	}

	public function testMissingHybridTermsRetryExplainsSearchAndChatBranches(): void {
		$prompts = [];
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(2))->method('generateToolCall')->willReturnCallback(
			function (string $prompt) use (&$prompts): array {
				$prompts[] = $prompt;
				return sizeof($prompts) === 1
					? $this->toolResponse('Find more combat boots for men')
					: $this->toolResponse('Find more combat boots for men', '', ['combat boots', 'men']);
			}
		);
		$history = new ConversationHistory([['role' => 'user', 'message' => 'Combat boots']]);
		$result = (new ConversationRouter())->route('For men please', $history, $provider, [], 'hybrid');
		$this->assertSame(['combat boots', 'men'], $result->lexicalTerms);
		$this->assertStringContainsString('SEARCH requires 1 to 4 non-empty lexical_terms', $prompts[1]);
		$this->assertStringContainsString('current message or relevant history', $prompts[1]);
		$this->assertStringEndsWith($prompts[0], $prompts[1]);
	}

	public function testRepeatedMissingHybridTermsConstrainFinalAttemptToSearchSchema(): void {
		$tools = [];
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(3))->method('generateToolCall')->willReturnCallback(
			function (string $prompt, array $tool) use (&$tools): array {
				$this->assertStringContainsString('No, not these', $prompt);
				$tools[] = $tool;
				return sizeof($tools) === 3
					? $this->toolResponse('Find more combat boots', '', ['combat boots'])
					: $this->toolResponse('Find more combat boots');
			}
		);
		$route = (new ConversationRouter())->route(
			'No, not these', new ConversationHistory([['role' => 'user', 'message' => 'Combat boots']]),
			$provider, [], 'hybrid'
		);
		$this->assertSame(['combat boots'], $route->lexicalTerms);
		$this->assertSame(
			[ConversationRoute::SEARCH, ConversationRoute::CHAT],
			$tools[1]['parameters']['properties']['route']['enum']
		);
		$this->assertSame([ConversationRoute::SEARCH], $tools[2]['parameters']['properties']['route']['enum']);
		$this->assertSame(1, $tools[2]['parameters']['properties']['lexical_terms']['minItems']);
	}

	public function testSearchOnlyRepairRejectsChatInsteadOfSilentlySwitchingRoute(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(3))->method('generateToolCall')->willReturnOnConsecutiveCalls(
			$this->toolResponse('Find more combat boots'),
			$this->toolResponse('Find more combat boots'),
			$this->rawToolResponse(
				['route' => ConversationRoute::CHAT, 'standalone_question' => '',
				'exclude_query' => '', 'reason' => 'No more shopping', 'lexical_terms' => []]
			)
		);
		try {
			(new ConversationRouter())->route('Find more boots', new ConversationHistory([]), $provider, [], 'hybrid');
			$this->fail('Expected the SEARCH-only repair to reject CHAT');
		} catch (ManticoreSearchClientError $error) {
			$this->assertStringContainsString('SEARCH-only', $error->getResponseError());
		}
	}

	public function testInvalidChatFieldsRetryExplainsEmptyFieldsInKnnAndHybrid(): void {
		foreach (['knn', 'hybrid'] as $strategy) {
			$prompts = [];
			$provider = $this->createMock(LlmProvider::class);
			$provider->expects($this->exactly(2))->method('generateToolCall')->willReturnCallback(
				function (string $prompt) use (&$prompts, $strategy): array {
					$prompts[] = $prompt;
					return $this->rawToolResponse(
						['route' => ConversationRoute::CHAT,
							'standalone_question' => sizeof($prompts) === 1 ? 'Find jeans' : '',
							'exclude_query' => '', 'reason' => 'Done shopping',
							...($strategy === 'hybrid' ? ['lexical_terms' => []] : [])]
					);
				}
			);
			$route = (new ConversationRouter())->route(
				'I am satisfied for now', new ConversationHistory([]), $provider, [], $strategy
			);
			$this->assertSame(ConversationRoute::CHAT, $route->route);
			$this->assertStringContainsString('CHAT requires empty standalone_question and exclude_query', $prompts[1]);
			$this->assertStringEndsWith($prompts[0], $prompts[1]);
		}
	}

	public function testInvalidQuestionRetriesAndReturnsRoute(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(2))->method('generateToolCall')->willReturnOnConsecutiveCalls(
			$this->toolResponse(' '), $this->toolResponse('What is clustering?')
		);
		$result = (new ConversationRouter())->route(
			'What is clustering?', $this->history(), $provider, []
		);
		$this->assertEquals(
			new ConversationRoute(ConversationRoute::SEARCH, 'What is clustering?', '', 'Search indexed data'),
			$result
		);
	}

	public function testProviderAndValidationFailuresShareThreeAttemptBudget(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(3))->method('generateToolCall')->willReturnOnConsecutiveCalls(
			['success' => false, 'error' => 'LLM tool call failed', 'content' => '', 'provider' => 'llm'],
			$this->toolResponse(''),
			['success' => false, 'error' => 'LLM tool call failed', 'content' => '',
				'provider' => 'llm', 'details' => 'Provider unavailable']
		);
		try {
			(new ConversationRouter())->route('What is clustering?', $this->history(), $provider, []);
			$this->fail('Expected failure after three attempts');
		} catch (ManticoreSearchClientError $error) {
			$this->assertSame(
				'Conversation routing failed: LLM tool call failed: Provider unavailable',
				$error->getResponseError()
			);
		}
	}

	public function testMalformedToolCallsRetryUntilValid(): void {
		$valid = $this->toolResponse('Which listed TV show is fantasy?');
		$empty = $valid;
		$empty['tool_calls'] = [];
		$toolCall = $this->createMock(ToolCall::class);
		$toolCall->method('getArguments')->willReturn('{');
		$malformed = $valid;
		$malformed['tool_calls'] = [$toolCall];
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(3))->method('generateToolCall')
			->willReturnOnConsecutiveCalls($empty, $malformed, $valid);
		$result = (new ConversationRouter())->route(
			'Which one is fantasy?', $this->history(), $provider, []
		);
		$this->assertSame('Which listed TV show is fantasy?', $result->standaloneQuestion);
	}

	public function testParsesArrayToolCallArguments(): void {
		$toolCall = $this->createMock(ToolCall::class);
		$toolCall->method('getArguments')->willReturn(
			['route' => ConversationRoute::SEARCH, 'standalone_question' => 'What is Chat?',
				'exclude_query' => '', 'reason' => 'Search indexed data']
		);
		$provider = $this->createMock(LlmProvider::class);
		$provider->method('generateToolCall')->willReturn($this->response([$toolCall]));
		$result = (new ConversationRouter())->route(
			'What is Chat?', new ConversationHistory([]), $provider, []
		);
		$this->assertSame('What is Chat?', $result->standaloneQuestion);
	}

	public function testPureSocialMessagesRouteToChatIncludingHybrid(): void {
		foreach (['Hello', 'Bye', 'thanks', 'How are you?', 'Tell me a joke', 'I am satisfied for now'] as $utterance) {
			foreach (['knn', 'hybrid'] as $strategy) {
				$provider = $this->createMock(LlmProvider::class);
				$provider->expects($this->once())->method('generateToolCall')->with(
					$this->callback(
						static fn (string $prompt): bool => str_contains(
							$prompt, 'Use CHAT only when no indexed information is requested'
						)
						&& str_contains($prompt, 'shopping or purchase requests such as "Buy"')
						&& str_contains($prompt, 'I am satisfied for now')
						&& str_contains($prompt, "  $utterance</Question>")
						&& !str_contains($prompt, 'Always generate a non-empty standalone_question')
					)
				)->willReturn(
					$this->rawToolResponse(
						[
						'route' => ConversationRoute::CHAT, 'standalone_question' => '', 'exclude_query' => '',
						'reason' => 'Unrelated conversation',
						...($strategy === 'hybrid' ? ['lexical_terms' => []] : []),
						]
					)
				);
				$this->assertEquals(
					new ConversationRoute(ConversationRoute::CHAT, '', '', 'Unrelated conversation'),
					(new ConversationRouter())->route($utterance, $this->history(), $provider, [], $strategy)
				);
			}
		}
	}

	public function testBuyAndMixedSocialRequestsStaySearches(): void {
		foreach (['Buy', 'Hello, find a frame', 'Thanks, show more frames',
			'No I think I have heard enough; how can I buy?'] as $utterance) {
			$provider = $this->createMock(LlmProvider::class);
			$provider->expects($this->once())->method('generateToolCall')->with(
				$this->callback(
					static fn (string $prompt): bool => str_contains($prompt, 'how can I buy?')
				)
			)->willReturn(
				$this->toolResponse('Find available frames')
			);
			$this->assertSame(
				ConversationRoute::SEARCH, (new ConversationRouter())->route(
					$utterance, new ConversationHistory([]), $provider, []
				)->route
			);
		}
	}

	public function testInvalidActionsAndChatFieldsRetryWithoutFallback(): void {
		$invalid = [
			['standalone_question' => '', 'exclude_query' => ''],
			['route' => 'REJECT', 'standalone_question' => '', 'exclude_query' => ''],
			['route' => ConversationRoute::CHAT, 'standalone_question' => 'Find frames', 'exclude_query' => ''],
			['route' => ConversationRoute::CHAT, 'standalone_question' => '', 'exclude_query' => 'frames'],
			['route' => ConversationRoute::CHAT, 'standalone_question' => ' ', 'exclude_query' => ''],
			['route' => ConversationRoute::CHAT, 'standalone_question' => '', 'exclude_query' => ' '],
			['route' => ConversationRoute::CHAT, 'standalone_question' => '', 'exclude_query' => 1],
			['route' => ConversationRoute::CHAT, 'standalone_question' => 'Find frames',
				'exclude_query' => '', 'lexical_terms' => []],
		];
		foreach ($invalid as $index => $arguments) {
			$hybrid = $index >= 7;
			$provider = $this->createMock(LlmProvider::class);
			$provider->expects($this->exactly(2))->method('generateToolCall')->willReturnOnConsecutiveCalls(
				$this->rawToolResponse(['reason' => 'Unrelated conversation', ...$arguments]),
				$this->rawToolResponse(
					['route' => ConversationRoute::CHAT, 'standalone_question' => '',
					'exclude_query' => '', 'reason' => 'Unrelated conversation',
					...($hybrid ? ['lexical_terms' => []] : [])]
				)
			);
			$this->assertSame(
				ConversationRoute::CHAT, (new ConversationRouter())->route(
					'Hello', new ConversationHistory([]), $provider, [], $hybrid ? 'hybrid' : 'knn'
				)->route
			);
		}
	}

	public function testInvalidChatExhaustsRetryBudget(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(3))->method('generateToolCall')->willReturn(
			$this->rawToolResponse(
				['route' => ConversationRoute::CHAT, 'standalone_question' => 'Find frames',
				'exclude_query' => '', 'reason' => 'Unrelated conversation', 'lexical_terms' => ['frame']]
			)
		);
		$this->expectException(ManticoreSearchClientError::class);
		(new ConversationRouter())->route('Hello', new ConversationHistory([]), $provider, [], 'hybrid');
	}

	public function testChatDoesNotRejectUnusedLexicalTermsWhenSearchFieldsAreEmpty(): void {
		foreach (['knn', 'hybrid'] as $strategy) {
			foreach ([['jeans', 'stretch'], null] as $unusedTerms) {
				$provider = $this->createMock(LlmProvider::class);
				$provider->expects($this->once())->method('generateToolCall')->willReturn(
					$this->rawToolResponse(
						['route' => ConversationRoute::CHAT, 'standalone_question' => '',
						'exclude_query' => '', 'reason' => 'Acknowledgement',
						...($unusedTerms === null ? [] : ['lexical_terms' => $unusedTerms])]
					)
				);
				$result = (new ConversationRouter())->route(
					'I am satisfied for now', new ConversationHistory([]), $provider, [], $strategy
				);
				$this->assertSame(ConversationRoute::CHAT, $result->route);
				$this->assertSame([], $result->lexicalTerms);
			}
		}
	}

	public function testMultipleToolCallsRetryRatherThanIgnoringAnExtraCall(): void {
		$extra = $this->createMock(ToolCall::class);
		$extra->method('getArguments')->willReturn(
			['route' => ConversationRoute::CHAT, 'reason' => 'Unrelated conversation',
			'standalone_question' => '', 'exclude_query' => '']
		);
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(2))->method('generateToolCall')->willReturnOnConsecutiveCalls(
			$this->response([$extra, $extra]),
			$this->response([$extra])
		);
		$this->assertSame(
			ConversationRoute::CHAT, (new ConversationRouter())->route(
				'Hello', new ConversationHistory([]), $provider, []
			)->route
		);
	}

	private function history(): ConversationHistory {
		return new ConversationHistory(
			[
			['role' => 'user', 'message' => 'tv shows?'],
			['role' => 'assistant', 'message' => "1. Breaking Bad: crime drama.\n2. Game of Thrones: fantasy drama."],
			]
		);
	}

	/**
	 * @param list<string>|null $terms
	 * @return array<string, mixed>
	 */
	private function toolResponse(string $question, string $exclude = '', ?array $terms = null): array {
		$arguments = ['route' => ConversationRoute::SEARCH, 'standalone_question' => $question,
			'exclude_query' => $exclude, 'reason' => 'Search indexed data'];
		if ($terms !== null) {
			$arguments['lexical_terms'] = $terms;
		}
		return $this->rawToolResponse($arguments);
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @return array<string, mixed>
	 */
	private function rawToolResponse(array $arguments): array {
		$toolCall = $this->createMock(ToolCall::class);
		$toolCall->method('getArguments')->willReturn(json_encode($arguments, JSON_THROW_ON_ERROR));
		return $this->response([$toolCall]);
	}

	/**
	 * @param array<int, mixed> $calls
	 * @return array<string, mixed>
	 */
	private function response(array $calls): array {
		return ['success' => true, 'content' => '', 'tool_calls' => $calls, 'metadata' => [
			'tokens_used' => 10, 'input_tokens' => 8, 'output_tokens' => 2,
			'response_time_ms' => 1, 'finish_reason' => 'tool_calls',
		]];
	}
}

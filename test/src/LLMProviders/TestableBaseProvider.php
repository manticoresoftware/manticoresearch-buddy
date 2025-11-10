<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\LLMProviders;

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders\BaseProvider;

/**
 * Concrete implementation of BaseProvider for testing
 */
class TestableBaseProvider extends BaseProvider {
	public function generateResponse(string $prompt = '', array $options = []): array {
		unset($prompt, $options);
		return ['success' => true, 'content' => 'test response'];
	}

	public function getSupportedModels(): array {
		return ['test-model'];
	}

	protected function createClient(): object {
		return (object)['test' => 'client'];
	}

	public function getName(): string {
		return 'test_provider';
	}
}

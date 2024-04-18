<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\AlterFactory;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\CreateFactory;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\DropFactory;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\ShowFactory;

class SqlModelsHandler
{

	public static function handle(array $parsed): ?Model {

		if (isset($parsed['ALTER'])) {
			return AlterFactory::create($parsed);
		}

		if (isset($parsed['CREATE'])) {
			return CreateFactory::create($parsed);
		}

		if (isset($parsed['DROP'])) {
			return DropFactory::create($parsed);
		}

		if (isset($parsed['SHOW'])) {
			return ShowFactory::create($parsed);
		}

		return null;
	}
}

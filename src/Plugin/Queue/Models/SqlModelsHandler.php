<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\AlterFactory;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\CreateFactory;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\DropFactory;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\ShowFactory;

class SqlModelsHandler
{

	/**
	 * @template T of array
	 * @phpstan-param T $parsed
	 * @phpstan-return Model<T>|null
	 */
	public static function handle(?array $parsed): ?Model {

		// Order here is important !!!
		if (isset($parsed['ALTER'])) {
			/** @var Model<T>|null $result */
			$result = AlterFactory::create($parsed);
			return $result;
		}

		if (isset($parsed['CREATE'])) {
			/** @var Model<T>|null $result */
			$result = CreateFactory::create($parsed);
			return $result;
		}

		if (isset($parsed['DROP'])) {
			/** @var Model<T>|null $result */
			$result = DropFactory::create($parsed);
			return $result;
		}

		if (isset($parsed['SHOW'])) {
			/** @var Model<T>|null $result */
			$result = ShowFactory::create($parsed);
			return $result;
		}

		return null;
	}
}

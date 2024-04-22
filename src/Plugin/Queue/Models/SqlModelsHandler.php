<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\AlterFactory;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\CreateFactory;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\DropFactory;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories\ShowFactory;

class SqlModelsHandler {

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

<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Create\CreateMaterializedViewModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Create\CreateSourceModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;

class CreateFactory
{
	public static function create(array $parsedPayload): ?Model {

		$model = null;

		if (self::isCreateSourceMatch($parsedPayload)) {
			$model = new CreateSourceModel($parsedPayload);
		}

		if (self::isMaterializedViewMatch($parsedPayload)) {
			$model = new CreateMaterializedViewModel($parsedPayload);
		}

		return $model;
	}


	private static function isCreateSourceMatch(array $parsedPayload): bool {
		return (
			!empty($parsedPayload['SOURCE']['no_quotes']['parts'][0]) &&
			!empty($parsedPayload['SOURCE']['create-def']) &&
			!empty($parsedPayload['SOURCE']['options'])
		);
	}


	private static function isMaterializedViewMatch(array $parsedPayload): bool {
		return (
			isset($parsedPayload['SELECT']) &&
			isset($parsedPayload['FROM']) &&
			!empty($parsedPayload['VIEW']['no_quotes']['parts'][0]) &&
			!empty($parsedPayload['VIEW']['to'])
		);
	}
}

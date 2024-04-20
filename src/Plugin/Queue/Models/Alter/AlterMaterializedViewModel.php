<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Alter;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;

/**
 * @template T of array
 * @extends Model<T>
 */
class AlterMaterializedViewModel extends Model
{

	#[\Override] public function getHandlerClass(): string {
		return 'Handlers\\View\\AlterViewHandler';
	}
}

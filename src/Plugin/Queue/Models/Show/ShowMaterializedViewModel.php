<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Show;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;

/**
 * @template T of array
 * @extends Model<T>
 */
class ShowMaterializedViewModel extends Model
{

	#[\Override] public function getHandlerClass(): string {
		return 'Handlers\\View\\GetViewHandler';
	}
}

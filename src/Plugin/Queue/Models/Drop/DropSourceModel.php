<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Drop;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;

class DropSourceModel extends Model
{

	#[\Override] public function getHandlerClass(): string {
		return 'Handlers\\Source\\DropSourceHandler';
	}
}

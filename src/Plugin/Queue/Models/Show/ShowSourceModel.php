<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Show;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;

class ShowSourceModel extends Model
{

	#[\Override] public function getHandlerClass(): string {
		return 'Handlers\\Source\\GetSourceHandler';
	}
}

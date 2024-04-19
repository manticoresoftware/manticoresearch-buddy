<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Create;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;

class CreateMaterializedViewModel extends Model
{


	#[\Override] public function getHandlerClass(): string {
		return 'Handlers\\View\\CreateViewHandler';
	}
}

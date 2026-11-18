<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if not, see <https://www.gnu.org/licenses/>.
*/

namespace Manticoresearch\BuddyTest\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\SystemClient;

final class AddEntityHandlerClient extends Client {
	public function __construct(private AddEntityHandlerRecords $records) {
		parent::__construct();
	}

	public function getSystemClient(): SystemClient {
		return $this->systemClient ??= new AddEntityHandlerSystemClient($this->records);
	}
}

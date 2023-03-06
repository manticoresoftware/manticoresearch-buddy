<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base;

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Interface\CommandRequestInterface;
use Manticoresearch\Buddy\Network\ManticoreClient\Settings as ManticoreSettings;
use Manticoresearch\Buddy\Network\Request;

abstract class CommandRequestBase implements CommandRequestInterface {
	protected ManticoreSettings $manticoreSettings;

	/**
	 * Set current settings to use in request
	 *
	 * @param ManticoreSettings $manticoreSettings
	 * @return static
	 */
	public function setManticoreSettings(ManticoreSettings $manticoreSettings): static {
		$this->manticoreSettings = $manticoreSettings;
		return $this;
	}

	/**
	 * Get current settings
	 * @return ManticoreSettings
	 */
	public function getManticoreSettings(): ManticoreSettings {
		return $this->manticoreSettings;
	}

	/**
	 * Redirect all /cli requests to /sql endpoint
	 *
	 * @param Request $request
	 * @return array{0:string,1:boolean}
	 */
	public static function getEndpointInfo(Request $request): array {
		return ($request->endpointBundle === ManticoreEndpoint::Cli)
			? [ManticoreEndpoint::Sql->value, true] : [$request->path, false];
	}
}

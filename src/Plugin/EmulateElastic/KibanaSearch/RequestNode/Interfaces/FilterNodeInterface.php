<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Interfaces;

/**
 *  Handles Kibana request 'Filter' nodes
 */
interface FilterNodeInterface {

	/**
	 * @return bool
	 */
	public function isDirect(): bool;

	/**
	 * @return array<mixed>
	 */
	public function getFilter(): array;
}

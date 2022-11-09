<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Interface\MntResponseBuilderInterface;
use Manticoresearch\Buddy\Interface\MntResponseInterface;

class MntResponseBuilder implements MntResponseBuilderInterface {

	public static function buildFromBody(string $body): MntResponseInterface {
		return new MntResponse($body);
	}

}

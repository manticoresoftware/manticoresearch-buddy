<?php declare(strict_types=1);

/*
 Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser;

enum Datatype: string {
	case Json = 'json';
	case Multi = 'multi';
	case Multi64 = 'multi64';
	case Float = 'float';
	case Int = 'int';
	case Bigint = 'bigint';
	case Text = 'text';
	case String = 'string';
	case Timestamp = 'timestamp';
	case Null = 'null';
}

<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Enum;

enum DATATYPE: string {
	case T_JSON = 'json';
	case T_MULTI = 'multi';
	case T_MULTI_64 = 'multi64';
	case T_FLOAT = 'float';
	case T_INT = 'int';
	case T_BIGINT = 'bigint';
	case T_TEXT = 'text';
	case T_STRING = 'string';
}

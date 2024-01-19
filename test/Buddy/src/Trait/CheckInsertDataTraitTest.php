<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\CheckInsertDataTrait;
use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\Datatype;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class CheckInsertDataTraitTest extends TestCase {

	use CheckInsertDataTrait;
	use TestProtectedTrait;

	public function testCheckUnescapedCharsOk():void {
		echo "\nTesting the detection of unescaped data characters\n";
		$data = 'some correct data';
		$this->checkUnescapedChars($data, GenericError::class);
		$this->assertTrue(true);
	}

	public function testCheckUnescapedCharsFail():void {
		echo "\nTesting the detection of unescaped data characters\n";
		$data = 'some "incorrect" data';
		$this->expectException(GenericError::class);
		$this->expectExceptionMessage("Unescaped '\"' character found in INSERT statement");
		$this->checkUnescapedChars($data, GenericError::class);
		$this->assertTrue(true);
	}

	public function testCheckIfManticoreString():void {
		echo "\nTesting the differentiation between of Manticore 'text' and 'string' types\n";
		$this->assertFalse(self::invokeMethod($this, 'isManticoreString', ['just text']));
		$this->assertTrue(self::invokeMethod($this, 'isManticoreString', ['test@mail.com']));
	}

	public function testStringifyColTypes():void {
		echo "\nTesting the conversion from enum:Datatype to string\n";
		$in = [Datatype::Bigint, Datatype::Json];
		$out = ['bigint', 'json'];
		$this->assertEquals($out, self::invokeMethod($this, 'stringifyColTypes', [$in]));
	}

	public function testColumnTypesCompatibilityOk():void {
		echo "\nTesting the check of compatible types in data passed\n";
		// 'types' argument must be passed by reference
		$types = [Datatype::Int, Datatype::Text];
		$args = [
			[Datatype::Int, Datatype::Text],
			&$types,
			['col1', 'col2'],
			RuntimeException::class,
		];
		$this->assertNull(self::invokeMethod($this, 'checkColTypesCompatibilityError', $args));

		echo "\nTesting the update of types info by check results\n";
		$types = [Datatype::Int, Datatype::String];
		$curTypes = [Datatype::Int, Datatype::Text];
		$args = [
			$curTypes,
			&$types,
			['col1', 'col2'],
			RuntimeException::class,
		];
		$this->assertNull(self::invokeMethod($this, 'checkColTypesCompatibilityError', $args));
		$this->assertEquals($types, $curTypes);
	}

	public function testColumnTypesCompatibilityFail():void {
		echo "\nTesting the check of incompatible types in data passed\n";
		// 'types' argument must be passed by reference
		$types = [Datatype::Int, Datatype::Json];
		$args = [
			[Datatype::Int, Datatype::Text],
			&$types,
			['col1', 'col2'],
			RuntimeException::class,
		];
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Incompatible types in 'col2': 'text json',");
		self::invokeMethod($this, 'checkColTypesCompatibilityError', $args);
	}

}

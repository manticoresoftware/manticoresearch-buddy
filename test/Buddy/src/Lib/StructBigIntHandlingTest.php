<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\TestCase;

final class StructBigIntHandlingTest extends TestCase {

	public function testLeadingZerosNumericStringIsNotTreatedAsBigInt(): void {
		$leadingZeros = str_repeat('0', 40);
		$bigUnsigned = '18446744073709551615';

		$json = '{"data":[{"leading_zeros":"' . $leadingZeros . '","big":' . $bigUnsigned . '}]}';
		$struct = Struct::fromJson($json);

		$this->assertContains('data.0.big', $struct->getBigIntFields());
		$this->assertNotContains('data.0.leading_zeros', $struct->getBigIntFields());

		$encoded = $struct->toJson();
		$this->assertTrue(Struct::isValid($encoded));
		$this->assertStringContainsString('"leading_zeros":"' . $leadingZeros . '"', $encoded);
		$this->assertStringContainsString('"big":' . $bigUnsigned, $encoded);
	}
}

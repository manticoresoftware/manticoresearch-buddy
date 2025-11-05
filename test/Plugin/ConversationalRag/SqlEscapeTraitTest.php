<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\SqlEscapeTrait;
use PHPUnit\Framework\TestCase;

/**
 * Test class for SqlEscapeTrait
 */
class SqlEscapeTraitTestClass {
	use SqlEscapeTrait;
}

class SqlEscapeTraitTest extends TestCase {
	private SqlEscapeTraitTestClass $testClass;

	protected function setUp(): void {
		$this->testClass = new SqlEscapeTraitTestClass();
	}

	public function testSqlEscape_SingleQuotes(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->testClass);
		$method = $reflection->getMethod('sqlEscape');
		$method->setAccessible(true);

		$result = $method->invoke($this->testClass, "Don't worry");
		$this->assertEquals("Don\\'t worry", $result);
	}

	public function testSqlEscape_Backslashes(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->testClass);
		$method = $reflection->getMethod('sqlEscape');
		$method->setAccessible(true);

		$result = $method->invoke($this->testClass, 'Path\\to\\file');
		$this->assertEquals('Path\\to\\file', $result); // Backslashes are not escaped in SQL
	}

	public function testSqlEscape_MultipleSpecialChars(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->testClass);
		$method = $reflection->getMethod('sqlEscape');
		$method->setAccessible(true);

		$result = $method->invoke($this->testClass, 'Test!@#$%^&*()');
		$this->assertEquals('Test!@#$%^&*()', $result); // Only single quotes are escaped in SQL
	}

	public function testSqlEscape_NoSpecialChars(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->testClass);
		$method = $reflection->getMethod('sqlEscape');
		$method->setAccessible(true);

		$result = $method->invoke($this->testClass, 'NormalString123');
		$this->assertEquals('NormalString123', $result);
	}

	public function testQuote_WrapsAndEscapes(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->testClass);
		$method = $reflection->getMethod('quote');
		$method->setAccessible(true);

		$result = $method->invoke($this->testClass, "O'Reilly");
		$this->assertEquals("'O\\'Reilly'", $result);
	}

	public function testQuote_EmptyString(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->testClass);
		$method = $reflection->getMethod('quote');
		$method->setAccessible(true);

		$result = $method->invoke($this->testClass, '');
		$this->assertEquals("''", $result);
	}

	public function testQuote_NumericString(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->testClass);
		$method = $reflection->getMethod('quote');
		$method->setAccessible(true);

		$result = $method->invoke($this->testClass, '123');
		$this->assertEquals("'123'", $result);
	}

	public function testQuote_SpecialCharsWithSingleQuote(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->testClass);
		$method = $reflection->getMethod('quote');
		$method->setAccessible(true);

		// Test SQL escaping - only single quotes are escaped
		$specialString = 'Don\'t escape "double quotes" or $special chars';
		$result = $method->invoke($this->testClass, $specialString);

		$expected = "'Don\\'t escape \"double quotes\" or \$special chars'";
		$this->assertEquals($expected, $result);
	}
}

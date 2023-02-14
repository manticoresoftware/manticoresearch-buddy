<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use PHPUnit\Framework\TestCase;

/**
 * This class tests that we properly build release version of Buddy
 * And this version of binary run and also does not include any of dev composer deps in it
 */
final class BuildTest extends TestCase {
	public function testBuildIsOk(): void {
		static::buildBinary();
		$this->assertEquals(true, file_exists('build/manticore-buddy.phar'));
		$this->assertEquals(true, file_exists('build/manticore-buddy'));
	}

	public function testBuildHasRightComposerPackages(): void {
		/** @var array{require:array<string,string>,require-dev:array<string,string>} $composer */
		$composer = json_decode((string)file_get_contents('/workdir/composer.json'), true);
		$include = array_keys($composer['require']);
		$exclude = array_keys($composer['require-dev']);
		/** @var array{dev:bool,dev-package-names:array<string,string>} $installed */
		$installed = json_decode(
			(string)file_get_contents('phar:///workdir/build/manticore-buddy.phar/vendor/composer/installed.json'),
			true
		);
		$this->assertEquals(false, $installed['dev']);
		$this->assertEquals([], $installed['dev-package-names']);

		$phar = new Phar('build/manticore-buddy.phar', 0);
		$packages = [];
		/** @var SplFileInfo $file */
		foreach (new RecursiveIteratorIterator($phar) as $file) {
			$pos = strpos((string)$file, 'manticore-buddy.phar/vendor/');
			if ($pos === false) {
				continue;
			}

			$ns = strtok(substr((string)$file, $pos + 28), '/');
			$name = strtok('/');
			$packages["$ns/$name"] = true;
		}

		$packages = array_keys($packages);
		$this->assertEquals([], array_diff($include, $packages));
		$this->assertEquals($exclude, array_diff($exclude, $packages));
	}

	protected static function buildBinary(): void {
		system('phar_builder/bin/build --name="Manticore Buddy" --package="manticore-buddy" --index="src/main.php"');
	}
}

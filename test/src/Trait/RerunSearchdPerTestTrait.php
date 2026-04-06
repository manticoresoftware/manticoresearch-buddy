<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Trait;

/**
 * Per-test searchd lifecycle trait.
 * Each test gets a fresh searchd instance (start in setUp, stop in tearDown).
 * Re-inits config every time so property changes (like $searchdArgs) are picked up.
 */
trait RerunSearchdPerTestTrait {
	use TestFunctionalTrait;

	// Class-level: only init config once
	public static function setUpBeforeClass(): void {
		static::initConfig();
	}

	// Safety cleanup after all tests
	public static function tearDownAfterClass(): void {
		static::stopSearchd();
	}

	// Each test gets a fresh searchd
	public function setUp(): void {
		static::initConfig();
		$this->beforeSearchdStart();
		static::startSearchd();
	}

	public function tearDown(): void {
		static::stopSearchd();
	}

	/**
	 * Hook: override in tests that need to do work before searchd starts
	 * @return void
	 */
	protected function beforeSearchdStart(): void {
	}
}

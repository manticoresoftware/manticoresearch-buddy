<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class BackupTest extends TestCase {

	use TestFunctionalTrait;

	public function testBackupReturnsError(): void {
		$this->assertQueryResultContainsError('backup', 'You have an error in your query. Please, double check it.');
		$this->assertQueryResultContainsError('backup to /tmp', 'You have no tables to backup.');
		$this->assertQueryResultContainsError(
			'backup tables a to /unexisting/dir',
			'Failed to find the realpath of dir: /unexisting/dir'
		);
		$this->assertQueryResultContainsError('backup table c to /tmp', "Can't find some of the tables: c");
	}

	public function testBackupWorksWell(): void {
		// Prepare some tables first
		static::runSqlQuery('create table a');
		static::runSqlQuery('create table b');

		exec('rm -fr /tmp/backup1 /tmp/backup2 /tmp/backup3');
		exec('mkdir -p /tmp/backup1 /tmp/backup2 /tmp/backup3');

		$this->assertQueryResultContainsString('backup to /tmp/backup1', 'Path: /tmp/backup1/backup-');
		$this->assertQueryResultContainsString('backup tables a, b to /tmp/backup2', 'Path: /tmp/backup2/backup-');
		$this->assertQueryResultContainsString('backup table a to /tmp/backup3', 'Path: /tmp/backup3/backup-');

		static::runSqlQuery('drop table if exists a');
		static::runSqlQuery('drop table if exists b');
	}
}

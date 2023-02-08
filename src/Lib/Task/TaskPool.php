<?php declare(strict_types=1);

/*
	Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 2 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Lib\Task;

use RuntimeException;

/**
 * Simple container for running tasks
 */
final class TaskPool {
	/** @var array<int,Task> */
	protected static array $pool = [];

	/**
	 * @param Task $task
	 * @return void
	 */
	public static function add(Task $task): void {
		$taskId = $task->getId();
		if (isset(static::$pool[$taskId])) {
			throw new RuntimeException("Task {$taskId} already exists");
		}
		static::$pool[$taskId] = $task;
	}

	/**
	 * @param Task $task
	 * @return void
	 */
	public static function remove(Task $task): void {
		$taskId = $task->getId();
		if (!isset(static::$pool[$taskId])) {
			throw new RuntimeException("Task {$taskId} does not exist");
		}
		unset(static::$pool[$taskId]);
	}

	/**
	 * Get all active tasks in the pool
	 *
	 * @return array<int,Task>
	 */
	public static function getList(): array {
		return static::$pool;
	}

	/**
	 * Get total count of running tasks in a pool
	 *
	 * @return int
	 */
	public static function getCount(): int {
		return sizeof(static::$pool);
	}
}

<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Network;

use Exception;
use Manticoresearch\Buddy\Lib\QueryProcessor;
// @codingStandardsIgnoreStart
use Manticoresearch\Buddy\Lib\Task;
// @codingStandardsIgnoreEnd
use Manticoresearch\Buddy\Lib\TaskStatus;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response as HttpResponse;
use Throwable;

/**
 * This is the main class that contains all handlers
 * for work with connection initiated by React framework
 */
final class EventHandler {
	/** @var array<int,Task> */
	protected static array $tasks = [];

	/**
	 * Process 'data' event on connecction
	 *
	 * @param string $data
	 * @return Response
	 */
	protected static function data(string $data): Response {
		try {
			$request = Request::fromString($data);
			$executor = QueryProcessor::process($request);
			static::$tasks[] = $executor->run();
		} catch (Throwable $e) {
			return Response::fromError($e);
		}

		return Response::none();
	}

	/**
	 * Main handler for HTTP request that returns HttpResponse
	 *
	 * @param ServerRequestInterface $request
	 * @return HttpResponse
	 */
	public static function request(ServerRequestInterface $request): HttpResponse {
		static $headers = ['Content-Type' => 'application/json'];

		// Allow only post and otherwise send bad request
		if ($request->getMethod() !== 'POST') {
			return new HttpResponse(
				400, $headers, (string)Response::none()
			);
		}
		$data = (string)$request->getBody();
		$response = static::data($data);

		return new HttpResponse(200, $headers, (string)$response);
	}

	/**
	 * Process 'error' event on connection
	 *
	 * @param Exception $e
	 * @return Response
	 */
	public static function error(Exception $e): Response {
		var_dump($e);
		return Response::none();
	}

	/**
	 * Process ticker handler and check jobs
	 * It's related to the client side and should be added to client ns of ticker
	 * TODO: we should store tasks somewhere else and think about concurrent access to it
	 *
	 * @return array<Response>
	 */
	public static function taskStatusTicker(): array {
		$result = [];
		static::$tasks = array_filter(
			static::$tasks,
			function ($task) use (&$result) {
				$taskStatus = $task->getStatus();
				if (in_array($taskStatus, [TaskStatus::Pending, TaskStatus::Running])) {
					return true;
				}
				if ($taskStatus === TaskStatus::Failed) {
					$result[] = Response::fromError(new Exception('Buddy task failed to start'));
				} else {
					if ($task->isSucceed()) {
						$result[] = Response::fromString((string)$task->getResult());
					} else {
						$error = $task->getError()->getMessage();
						$result[] = Response::fromError(new Exception($error));
					}
				}
				return false;
			}
		);

		return $result;
	}

	/**
	 * Ticker to validate that client is alive or not
	 *
	 * @param int $pid
	 * @param string $pidPath
	 * @return callable
	 */
	public static function clientCheckTickerFn(int $pid, string $pidPath): callable {
		return function () use ($pid, $pidPath) {
			$pidFromFile = -1;
			if (file_exists($pidPath)) {
				$content = file_get_contents($pidPath);
				if ($content === false) {
					return false;
				}
				$pidFromFile = substr($content, 0, -1);
			}
			return $pid === $pidFromFile;
		};
	}
}

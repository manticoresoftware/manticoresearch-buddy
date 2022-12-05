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
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Lib\QueryProcessor;
use Manticoresearch\Buddy\Lib\TaskPool;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\Message\Response as HttpResponse;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * This is the main class that contains all handlers
 * for work with connection initiated by React framework
 */
final class EventHandler {
	/**
	 * Process 'data' event on connecction
	 *
	 * @param ServerRequestInterface $serverRequest
	 * @param string $data
	 * @return PromiseInterface
	 */
	protected static function data(ServerRequestInterface $serverRequest, string $data): PromiseInterface {
		$deferred = new Deferred;
		try {
			debug("Request data: $data");
			$request = Request::fromString($data);
			$executor = QueryProcessor::process($request);
			$task = $executor->run();
		} catch (Throwable $e) {
			debug("Data parse error: {$e->getMessage()}");
			return $deferred->resolve(Response::fromError($e, $request->format ?? RequestFormat::JSON));
		}

		// Get extra properties to identified connectio and host
		// and set it for the task first
		/** @var string $host */
		$host = $serverRequest->getHeader('Host')[0] ?? '';
		$task
			->setHost($host)
			->setBody($request->payload);
		// Add task to running pool first
		TaskPool::add($task);

		// We always add periodic timer, just because we keep tracking deferred tasks
		// to show it in case of show queries
		$tickFn = static function (TimerInterface $timer) use ($request, $task, $deferred) {
			static $ts;
			if (!isset($ts)) {
				$ts = time();
			}
			$now = time();
			// Dump debug message once a 5 sec
			if (($now - $ts) >= 5) {
				$ts = $now;
				$taskId = $task->getId();
				$taskStatus = $task->getStatus()->name;
				debug("Task $taskId is $taskStatus");
			}
			if ($task->isRunning()) {
				return;
			}
			// task is finished, we can remove it from the pool
			TaskPool::remove($task);

			Loop::cancelTimer($timer);
			if ($task->isSucceed()) {
				/** @var array<mixed> */
				$result = $task->getResult();
				if (!$task->isDeferred()) {
					return $deferred->resolve(Response::fromMessage($result, $request->format));
				}
			}

			if (!$task->isDeferred()) {
				return $deferred->resolve(Response::fromError($task->getError(), $request->format));
			}
		};

		// Run tick function and add timer only when task is still running or deferred
		$tickFn(Loop::addPeriodicTimer(0.005, $tickFn));

		// In case we work with deferred task we
		// should return response to the client without waiting
		// for results of it
		if ($task->isDeferred()) {
			// TODO: extract it somewhere for better code
			$response = [[
				'columns' => [
					'id' => [
						'type' => 'long long',
					],
				],
				'data' => [
					[
						'id' => $task->getId(),
					],
				],
			],
			];
			$deferred->resolve(Response::fromMessage($response, $request->format));
		}

		return $deferred->promise();
	}

	/**
	 * Main handler for HTTP request that returns HttpResponse
	 *
	 * @param ServerRequestInterface $request
	 * @return Promise
	 */
	public static function request(ServerRequestInterface $request): Promise {
		return new Promise(
			function (callable $resolve, callable $reject) use ($request) {
				static $headers = ['Content-Type' => 'application/json'];

				$data = (string)$request->getBody();
				$promise = static::data($request, $data);
				// Allow only post and otherwise send bad request
				if ($request->getMethod() !== 'POST') {
					return $reject(
						new HttpResponse(
							400, $headers, (string)Response::none()
						)
					);
				}

				$promise->then(
					function (Response $response) use ($headers, $resolve) {
						$result = (string)$response;
						debug("Response data: $result");
						return $resolve(new HttpResponse(200, $headers, $result));
					}
				);
			}
		);
	}

	/**
	 * Process 'error' event on connection
	 *
	 * @param Exception $e
	 * @return Response
	 */
	public static function error(Exception $e): Response {
		debug('Error: ' . $e->getMessage());
		return Response::none();
	}

	/**
	 * Ticker to validate that client is alive or not
	 *
	 * @return callable
	 */
	public static function clientCheckTickerFn(): callable {
		return function () {
			return process_exists(get_parent_pid());
		};
	}
}

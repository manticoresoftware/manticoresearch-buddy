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
use Manticoresearch\Buddy\Exception\CommandNotAllowed;
use Manticoresearch\Buddy\Exception\GenericError;
use Manticoresearch\Buddy\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Lib\QueryProcessor;
use Manticoresearch\Buddy\Lib\Task;
use Manticoresearch\Buddy\Lib\TaskPool;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\Message\Response as HttpResponse;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Throwable;
use parallel\Runtime;

/**
 * This is the main class that contains all handlers
 * for work with connection initiated by React framework
 */
final class EventHandler {
	/** @var array<Runtime> */
	public static array $runtimes = [];

	/** @var int */
	public static int $maxRuntimeIndex;

	/**
	 * This fucntion must be called before using any request processing to initizalize runtimes for threading
	 *
	 * @return void
	 */
	public static function init(): void {
		$threads = (int)(getenv('THREADS', true) ?: 4);
		static::$runtimes = [];
		for ($i = 0; $i < $threads; $i++) {
			static::$runtimes[] = Task::createRuntime();
		}
		static::$maxRuntimeIndex = $threads - 1;
	}

	/**
	 * @return void
	 */
	public static function destroy(): void {
		try {
			foreach (static::$runtimes as $runtime) {
				$runtime->kill();
			}
			static::$runtimes = [];
		} catch (Throwable) {
		}
	}

	/**
	 * Check if a custom error should be sent
	 *
	 * @param Throwable $e
	 * @return bool
	 */
	protected static function isCustomError(Throwable $e): bool {
		return is_a($e, SQLQueryCommandNotSupported::class) || is_a($e, CommandNotAllowed::class);
	}

	/**
	 * Process 'data' event on connecction
	 *
	 * @param ServerRequestInterface $serverRequest
	 * @param string $data
	 * @return PromiseInterface
	 */
	protected static function data(ServerRequestInterface $serverRequest, string $data): PromiseInterface {
		$deferred = new Deferred;
		$id = static::getRequestId($serverRequest);
		debug("[$id] request data: $data");
		try {
			$request = Request::fromString($data, $id);
			$executor = QueryProcessor::process($request);
			$task = $executor->run(static::$runtimes[mt_rand(0, static::$maxRuntimeIndex)]);
		} catch (Throwable $e) {
			debug("[$id] data parse error: {$e->getMessage()}");

			// Create special generic error in case we have system exception
			// And shadowing $e with it
			/** @var string $originalError */
			$originalError = match (true) {
				isset($request) => $request->error,
				default => ((array)json_decode($data, true))['error'] ?? '',
			};
			// We proxy original error in case when we do not know how to handle query
			// otherwise we send our custom error
			if (self::isCustomError($e)) {
				/** @var GenericError $e */
				$e->setResponseError($originalError);
			} elseif (!is_a($e, GenericError::class)) {
				$e = GenericError::create($originalError);
			}

			$deferred->resolve(
				[
					$request ?? Request::default(),
					Response::fromError($e, $request->format ?? RequestFormat::JSON),
				]
			);
			return $deferred->promise();
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
		$tickFn = static function (?TimerInterface $timer = null) use ($request, $task, $deferred) {
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
				debug("[$request->id] Task $taskId is $taskStatus");
			}
			if ($task->isRunning()) {
				return;
			}

			// task is finished, we can remove it from the pool
			TaskPool::remove($task);

			if ($timer) {
				Loop::cancelTimer($timer);
			}
			if ($task->isSucceed()) {
				/** @var array<mixed> */
				$result = $task->getResult();
				if (!$task->isDeferred()) {
					return $deferred->resolve([$request, Response::fromMessage($result, $request->format)]);
				}
			}

			if (!$task->isDeferred()) {
				return $deferred->resolve(
					[
						$request,
						Response::fromError($task->getError(), $request->format),
					]
				);
			}
		};

		// In case we work with deferred task we
		// should return response to the client without waiting
		// for results of it
		if ($task->isDeferred()) {
			$tickFn(Loop::addPeriodicTimer(0.001, $tickFn));
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
			$deferred->resolve(
				[
					$request,
					Response::fromMessage($response, $request->format),
				]
			);
		} else {
			$task->wait();
			$tickFn();
		}

		return $deferred->promise();
	}

	/**
	 * Main handler for HTTP request that returns HttpResponse
	 *
	 * @param ServerRequestInterface $serverRequest
	 * @return Promise
	 */
	public static function request(ServerRequestInterface $serverRequest): Promise {
		return new Promise(
			function (callable $resolve, callable $reject) use ($serverRequest) {
				static $headers = ['Content-Type' => 'application/json'];

				$data = (string)$serverRequest->getBody();
				$promise = static::data($serverRequest, $data);
				// Allow only post and otherwise send bad request
				if ($serverRequest->getMethod() !== 'POST') {
					return $reject(
						new HttpResponse(
							400, $headers, (string)Response::none()
						)
					);
				}

				$promise->then(
					/** @param array{0:Request,1:Response} $payload */
					function (array $payload) use ($headers, $resolve, $serverRequest) {
						[$request, $response] = $payload;
						$result = (string)$response;
						$id = static::getRequestId($serverRequest);
						debug("[$id] response data: $result");
						$time = (int)((microtime(true) - $request->time) * 1000000);
						debug("[$id] process time: {$time}µs");
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
	 * @param int $pid Initiale parent pid
	 * @return callable
	 */
	public static function clientCheckTickerFn(int $pid): callable {
		return function () use ($pid) {
			$curPid = get_parent_pid();
			if ($curPid === $pid && process_exists($pid)) {
				return;
			}

			debug('Parrent proccess died, exiting…');
			if (function_exists('posix_kill')) {
				posix_kill((int)getmypid(), 1);
			}
			exit(0);
		};
	}

	/**
	 * Get identifier for current request from headers
	 *
	 * @param ServerRequestInterface $serverRequest
	 * @return int
	 */
	protected static function getRequestId(ServerRequestInterface $serverRequest): int {
		return (int)($serverRequest->getHeader('Request-ID')[0] ?? 0);
	}
}

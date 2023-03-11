<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Network;

use Exception;
use Manticoresearch\Buddy\Base\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Base\Lib\QueryProcessor;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\InvalidNetworkRequestError;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Response;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskPool;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\Process;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\Message\Response as HttpResponse;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;
use parallel\Runtime;

/**
 * This is the main class that contains all handlers
 * for work with connection initiated by React framework
 */
final class EventHandler {
	// How many request we handle in single runtime after we destroy and create new one
	const RUNTIME_LIFETIME = 100;

	/** @var array{available:array<array{0:int,1:Runtime}>,blocked:array<array{0:int,1:Runtime}>} $runtimes */
	public static array $runtimes = [
		'available' => [],
		'blocked' => [],
	];

	/** @var int */
	public static int $maxRuntimeIndex;

	/**
	 * This fucntion must be called before using any request processing to initizalize runtimes for threading
	 *
	 * @return void
	 */
	public static function init(): void {
		$threads = (int)(getenv('THREADS', true) ?: 4);
		for ($i = 0; $i < $threads; $i++) {
			static::$runtimes['available'][] = [0, Task::createRuntime()];
		}
		static::$maxRuntimeIndex = $threads - 1;
	}

	/**
	 * @return void
	 */
	public static function destroy(): void {
		try {
			foreach (static::$runtimes['available'] as [, $runtime]) {
				$runtime->kill();
			}
			foreach (static::$runtimes['blocked'] as [, $runtime]) {
				$runtime->kill();
			}
			static::$runtimes = [
				'available' => [],
				'blocked' => [],
			];
		} catch (Throwable) {
		}
	}

	/**
	 * Check if a custom error should be sent
	 *
	 * @param Throwable $e
	 * @return bool
	 */
	protected static function shouldProxyError(Throwable $e): bool {
		return is_a($e, SQLQueryCommandNotSupported::class)
			|| is_a($e, InvalidNetworkRequestError::class)
			|| ($e instanceof GenericError && $e->getProxyOriginalError());
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
		Buddy::debug("[$id] request data: $data");

		// Get extra properties to identified connectio and host
		// and set it for the task first
		/** @var string $host */
		$host = $serverRequest->getHeader('Host')[0] ?? '';

		// We always add periodic timer, just because we keep tracking deferred tasks
		// to show it in case of show queries
		$tickFn = static function (?TimerInterface $timer = null) use ($id, $data, $host, $deferred) {
			static $ts;
			static $request;
			static $executor;
			static $task;
			static $firstCall = true;
			if (!isset($task)) {
				try {
					// we try to pop runtime and when we have some, process, otherwise just queue
					if (!static::$runtimes['available']) {
						return;
					}
					/** @var array{0:int,1:Runtime} */
					$runtimeStruct = array_pop(static::$runtimes['available']);
					[$usageCount, $runtime] = $runtimeStruct;
					static::$runtimes['blocked'][$id] = [++$usageCount, $runtime];
					$request = Request::fromString($data, $id);
					$executor = QueryProcessor::process($request);
					$task = $executor->run($runtime);
				} catch (Throwable $e) {
					return static::handleExceptionWhileDataProcessing(
						$e, $id, $request ?? null, $data, $deferred, $timer, $runtimeStruct ?? null
					);
				}

				$task
					->setHost($host)
					->setBody($request->payload);
				// Add task to running pool first
				TaskPool::add($task);
			}

			// Instantly return in case it's a deferred task
			if ($firstCall && $task->isDeferred()) {
				$firstCall = false;
				return static::handleDeferredTask($request, $deferred, $task);
			}

			if (!isset($ts)) {
				$ts = time();
			}
			$now = time();
			// Dump debug message once a 5 sec
			if (($now - $ts) >= 5) {
				$ts = $now;
				$taskId = $task->getId();
				$taskStatus = $task->getStatus()->name;
				Buddy::debug("[$request->id] Task $taskId is $taskStatus");
			}
			if ($task->isRunning()) {
				return;
			}

			static::handleTaskFinished($task, $timer, $id);

			if ($task->isSucceed()) {
				$result = $task->getResult();
				if (!$task->isDeferred()) {
					return $deferred->resolve(
						[$request, Response::fromMessage($result->getMessage(), $request->format)]
					);
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
		$tickFn(Loop::addPeriodicTimer(0.001, $tickFn));

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
						Buddy::debug("[$id] response data: $result");
						$time = (int)((microtime(true) - $request->time) * 1000000);
						Buddy::debug("[$id] process time: {$time}µs");
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
		Buddy::debug('Error: ' . $e->getMessage());
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
			$curPid = Process::getParentPid();
			if ($curPid === $pid && Process::exists($pid)) {
				return;
			}

			Buddy::debug('Parrent proccess died, exiting…');
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

	protected static function handleDeferredTask(Request $request, Deferred $deferred, Task $task): PromiseInterface {
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
		return $deferred->promise();
	}

	/**
	 *
	 * @param Throwable $e
	 * @param int $id
	 * @param ?Request $request
	 * @param string $data
	 * @param Deferred $deferred
	 * @param ?TimerInterface $timer
	 * @param ?array{0:int,1:Runtime} $runtimeStruct
	 * @return PromiseInterface
	 * @throws RuntimeException
	 */
	protected static function handleExceptionWhileDataProcessing(
		Throwable $e,
		int $id,
		?Request $request,
		string $data,
		Deferred $deferred,
		?TimerInterface $timer,
		?array $runtimeStruct
	): PromiseInterface {
		Buddy::debug("[$id] data parse error: {$e->getMessage()}");

		// If we pop runtime, return back in case of error
		if ($runtimeStruct) {
			[$usageCount, $runtime] = $runtimeStruct;
			array_unshift(static::$runtimes['available'], [++$usageCount, $runtime]);
		}

		// Create special generic error in case we have system exception
		// And shadowing $e with it
		/** @var string $originalError */
		$originalError = match (true) {
			isset($request) => $request->error,
			default => ((array)json_decode($data, true))['error'] ?? '',
		};
		// We proxy original error in case when we do not know how to handle query
		// otherwise we send our custom error
		if (self::shouldProxyError($e)) {
			/** @var GenericError $e */
			$e->setResponseError($originalError);
		} elseif (!is_a($e, GenericError::class)) {
			$e = GenericError::create($originalError);
		}

		static::handleTaskFinished(null, $timer, $id);
		$deferred->resolve(
			[
				$request ?? Request::default(),
				Response::fromError($e, $request->format ?? RequestFormat::JSON),
			]
		);

		return $deferred->promise();
	}

	public static function handleTaskFinished(?Task $task, ?TimerInterface $timer, int $requestId): void {
		// task is finished, we can remove it from the pool
		if (isset($task)) {
			TaskPool::remove($task);
		}

		if ($timer) {
			Loop::cancelTimer($timer);
		}

		// Check if runtime is expired and we need to create one
		// We do this just because we still do not know the reason of memory leak
		// When after many request of one type of query we switch to another one
		// that makes instant memory overflow
		// Return runtime back to pool
		if (static::$runtimes['blocked'][$requestId][0] > static::RUNTIME_LIFETIME) {
			static::$runtimes['blocked'][$requestId][1]->kill();
			unset(static::$runtimes['blocked'][$requestId]);
			static::$runtimes['blocked'][$requestId] = [0, Task::createRuntime()];
		}
		array_unshift(static::$runtimes['available'], static::$runtimes['blocked'][$requestId]);
		unset(static::$runtimes['blocked'][$requestId]);
	}
}

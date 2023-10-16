<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Network;

use Manticoresearch\Buddy\Base\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Base\Lib\QueryProcessor;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\InvalidNetworkRequestError;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Response;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\Process;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as SwooleServer;
use Swoole\Server\Task as SwooleTask;
use Throwable;

/**
 * This is the main class that contains all handlers
 * for work with connection initiated by React framework
 */
final class EventHandler {
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
	 * Main handler for HTTP request that returns HttpResponse
	 *
	 * @param Server $server
	 * @param SwooleRequest $request
	 * @param SwooleResponse $response
	 * @return void
	 */
	public static function request(Server $server, SwooleRequest $request, SwooleResponse $response): void {
		// Allow only post and otherwise send bad request
		if ($request->server['request_method'] !== 'POST') {
			$response->status(400);
			$response->end(Response::none());
			return;
		}

		$requestId = $request->header['Request-ID'] ?? '0';
		$result = $server->process($requestId, $request->rawContent() ?: '');
		// Send response
		$response->header('Content-Type', 'application/json');
		$response->status(200);
		$response->end((string)$result);
	}

	/**
	 * The event happens when we receive new task to process in async mode
	 * @param  SwooleServer $server
	 * @param  SwooleTask   $task
	 * @return void
	 */
	// phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
	public static function task(SwooleServer $server, SwooleTask $task): void {
		try {
			[$id, $payload] = $task->data;
			$request = Request::fromString($payload, $id);
			$handler = QueryProcessor::process($request)->run();
			// In case deferred we return the ID of the task not the request
			if ($handler->isDeferred()) {
				$result = TaskResult::withData([['id' => $handler->getId()]])
					->column('id', Column::Long);
			} else {
				$handler->wait(true);
				$result = $handler->getResult();
			}

			$response = Response::fromMessage($result->getStruct(), $request->format);
		} catch (Throwable $e) {
			/** @var string $originalError */
			$originalError = match (true) {
				isset($request) => $request->error,
				default => ((array)json_decode($payload, true))['error'] ?? '',
			};

			// We proxy original error in case when we do not know how to handle query
			// otherwise we send our custom error
			if (static::shouldProxyError($e)) {
				/** @var GenericError $e */
				$e->setResponseError($originalError);
			} elseif (!is_a($e, GenericError::class)) {
				$e = GenericError::create($originalError);
			}

			$response = Response::fromError($e, $request->format ?? RequestFormat::JSON);
		}
		$task->finish($response);
	}

	/**
	 * This method is used for async tasks, we can safely ignore it
	 * @param  SwooleServer $server
	 * @param  int          $fd
	 * @param  Response   $result
	 * @return void
	 */
	// phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
	public static function finish(SwooleServer $server, int $fd, Response $result): void {
		// $server
		// $fd
		// $result
	}

	/**
	 * Ticker to validate that client is alive or not
	 *
	 * @param int $swoolePid main process pid
	 * @param int $manticorePid parent pid
	 * @return callable
	 */
	public static function clientCheckTickerFn(int $swoolePid, int $manticorePid): callable {
		return function () use ($swoolePid, $manticorePid) {
			if (Process::exists($manticorePid)) {
				return;
			}

			Buddy::debug('Parrent proccess died, exiting…');
			\Swoole\Process::kill($swoolePid, 15);
		};
	}
}

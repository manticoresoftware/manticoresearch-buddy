<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Network;

use Manticoresearch\Buddy\Base\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Base\Lib\QueryProcessor;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\InvalidNetworkRequestError;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\OutputFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Response;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\TaskPool;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
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
	 * @param SwooleRequest $request
	 * @param SwooleResponse $response
	 * @return void
	 */
	public static function request(SwooleRequest $request, SwooleResponse $response): void {
		// Allow only post and otherwise send bad request
		if ($request->server['request_method'] !== 'POST') {
			$response->status(400);
			$response->end(Response::none());
			return;
		}
		$startTime = hrtime(true);
		$requestId = $request->header['Request-ID'] ?? uniqid(more_entropy: true);
		$body = $request->rawContent() ?: '';
		$channel = new Channel(1);
		Coroutine::create(
			static function () use ($requestId, $body, $channel, $startTime) {
				Buddy::debug("[$requestId] request data: $body");
				$result = (string)static::process($requestId, $body);
				Buddy::debug("[$requestId] response data: $result");
				Buddy::debug("[$requestId] response time: " . round((hrtime(true) - $startTime) / 1e6, 3) . ' ms');
				$channel->push($result);
			}
		);
		/** @var string $result */
		$result = $channel->pop();
		$response->header('Content-Type', 'application/json');
		$response->status(200);
		$response->end($result);
	}

	/**
	 * Process the main request
	 * @param  string $id
	 * @param  string $payload
	 * @return Response
	 */
	public static function process(string $id, string $payload): Response {
		try {
			/** @var int $startTime */
			$startTime = hrtime(true);
			$request = Request::fromString($payload, $id);
			$handler = QueryProcessor::process($request)->run();

			// In case deferred we return the ID of the task not the request
			if ($handler->isDeferred()) {
				$doneFn = TaskPool::add($id, $request->payload);
				$handler->on('failure', $doneFn)->on('success', $doneFn);
				$result = TaskResult::withData([['id' => $id]])
					->column('id', Column::String);
			} else {
				$handler->wait(true);
				$result = $handler->getResult();
			}

			// Check if this is a cli and we need to activate Table Formatter
			$outputFormat = $request->getOutputFormat();
			$message = match ($outputFormat) {
				// TODO: Maybe later we can use meta for time, but not now cuz no time for non select
				OutputFormat::Table => $result->getTableFormatted($startTime),
				default => $result->getStruct(),
			};
			$response = Response::fromMessageAndMeta(
				$message,
				$result->getMeta(),
				$request->format,
			);
		} catch (Throwable $e) {
			if (isset($request)) {
				/** @var string $originalError */
				$originalError = $request->error;
				/** @var array<mixed> $originalErrorBody */
				$originalErrorBody = $request->errorBody;
			} else {
				/** @var array{error?:array{message:string,body?:array{error:string}}} $payloadInfo */
				$payloadInfo = (array)json_decode($payload, true);
				$originalError = $payloadInfo['error']['message'] ?? '';
				$originalErrorBody = $payloadInfo['error']['body'] ?? [];
			}

			// We proxy original error in case when we do not know how to handle query
			// otherwise we send our custom error
			if (static::shouldProxyError($e)) {
				/** @var GenericError $e */
				$e->setResponseError($originalError);
				$e->setResponseErrorBody($originalErrorBody);
				Buddy::error($e, "[$id] processing error");
			} elseif (!is_a($e, GenericError::class)) {
				$e = GenericError::create($originalError);
			}

			$response = Response::fromError($e, $request->format ?? RequestFormat::JSON);
		}
		return $response;
	}
}

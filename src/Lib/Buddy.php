<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Enum\ACTION;
use Manticoresearch\Buddy\Interface\CustomErrorInterface;
use Manticoresearch\Buddy\Interface\QueryParserLocatorInterface;
use Manticoresearch\Buddy\Interface\StatementBuilderInterface;
use Manticoresearch\Buddy\Trait\CheckClientStateTrait;
use Manticoresearch\Buddy\Trait\CustomErrorTrait;
use Throwable;

class Buddy {

	use CustomErrorTrait;
	use CheckClientStateTrait;

	const ACTION_MAP = [
		'NO_INDEX' => [
			'INSERT_QUERY' => [ACTION::CREATE_INDEX, ACTION::INSERT],
		],
		'UNKNOWN_COMMAND' => [
			'SHOW_QUERIES_QUERY' => [ACTION::SELECT_SYSTEM_SESSIONS],
		],
	];

	/**
	 * @var string $query
	 */
	protected string $query;
	/**
	 * @var string $msg
	 */
	protected string $msg;
	/**
	 * @var string $origMsg
	 */
	protected string $origMsg;
	/**
	 * @var string $errorMsg
	 */
	protected string $errorMsg;
	/**
	 * @var string $reqFormat
	 */
	protected string $reqFormat;

	/**
	 * @param string $clAddrPort
	 * @param QueryParserLocatorInterface|null $queryParserLocator
	 * @param StatementBuilderInterface|null $stmtBuilder
	 * @param CustomErrorInterface|null $exceptionHandler,
	 * @return void
	 */
	public function __construct(
		protected string $clAddrPort,
		protected QueryParserLocatorInterface $queryParserLocator = null,
		protected StatementBuilderInterface $stmtBuilder = null,
		protected CustomErrorInterface $exceptionHandler = null,
	) {
		$this->msg = $this->errorMsg = $this->origMsg = '';
	}

	/**
	 * @param string $origMsg
	 * @param string $query
	 * @param string $reqFormat
	 * @return array{type:string, message:string, error:string}
	 */
	public function getResponse(string $origMsg, string $query, string $reqFormat): array {
		$this->origMsg = $origMsg;
		$this->query = $query;
		$this->reqFormat = $reqFormat;
		$this->msg = $this->errorMsg = '';
		$handleActions = $this->getHandleActions();
		if (!empty($handleActions)) {
			try {
				// sending the chain of needed requests to Manticore
				foreach ($handleActions as $action) {
					$mntRequest = $this->buildMntRequest($action);
					$this->msg = $this->getMntResponse($mntRequest);
					// checking if any of requests fails
					if ($this->isMntResponseOk() === false) {
						$this->errorMsg = $this->msg;
						break;
					}
				}
			} catch (Throwable $e) {
				$this->errorMsg = $e->getMessage();
			}
		}

		return $this->buildResponse();
	}

	/**
	 * @param string $message
	 * @param string $error
	 * @return array{type:string, message:string, error:string}
	 */
	public function buildResponse(string $message = '', string $error = ''): array {
		$error = $error ?: $this->errorMsg;
		if ($message === '') {
			if ($error === '') {
				$message = $this->msg;
			} elseif ($this->origMsg !== '') {
				$message = $this->origMsg;
			}
		}

		return ['type' => 'http response', 'message' => $message, 'error' => $error];
	}

	/**
	 * Checking if request can be handled by Buddy
	 *
	 * @return array<ACTION>
	 */
	protected function getHandleActions(): array {
		$errorType = $this->detectErrorType();
		if (isset(static::ACTION_MAP[$errorType])) {
			$queryType = $this->detectQueryType();
			return static::ACTION_MAP[$errorType][$queryType] ?? [];
		}
		return [];
	}

	/**
	 * @return string
	 */
	protected function detectErrorType(): string {
		// so far only use case with non-existing local index
		if (preg_match('/index (.*?) absent/', $this->origMsg)) {
			return 'NO_INDEX';
		}
		if (strpos($this->origMsg, 'unexpected identifier') !== false) {
			return 'UNKNOWN_COMMAND';
		}
		return '';
	}

	/**
	 * @return string
	 */
	protected function detectQueryType(): string {
		if (stripos($this->query, 'INSERT') === 0) {
			return 'INSERT_QUERY';
		}
		if (stripos($this->query, 'SHOW QUERIES') === 0) {
			return 'SHOW_QUERIES_QUERY';
		}
		return '';
	}

	/**
	 * @param ACTION $action
	 * @return string
	 */
	protected function buildMntRequest(ACTION $action): string {
		switch ($action) {
			case ACTION::INSERT:
				return $this->query;
			case ACTION::CREATE_INDEX:
				return $this->buildMntRequestWithHelpers('INSERT', 'CREATE');
			case ACTION::SELECT_SYSTEM_SESSIONS:
				return 'SELECT connid AS ID, `last cmd` AS query, host, proto FROM @@system.sessions)';
			default:
				return '';
		}
	}

	/**
	 * @param string $parseStmt
	 * @param string $buildStmt
	 * @return string
	 * @throws Throwable
	 */
	protected function buildMntRequestWithHelpers(string $parseStmt, string $buildStmt): string {
		try {
			if (!method_exists($this->queryParserLocator, 'getQueryParser')) {
				$this->error('Required parser cannot be found');
			} else {
				$parser = $this->queryParserLocator->getQueryParser($this->reqFormat, $parseStmt);
				if (method_exists($parser, 'parse')) {
					$queryData = $parser->parse($this->query);
					if (array_key_exists('error', $queryData)) {
						return $queryData['error'];
					}
					if (!method_exists($this->stmtBuilder, 'build')) {
						$this->error('Statement builder cannot be found');
					}
					return $this->stmtBuilder->build($buildStmt, $queryData);
				}
				$this->error('Unvalid parser detected');
			}
		} catch (Throwable $e) {
			$this->error($e->getMessage());
		}
		return '';
	}

	/**
	 * @param string $mntRequest
	 * @return string
	 */
	protected function getMntResponse(string $mntRequest): string {
		$resp  = '';
		$respEndpoint = "http://{$this->clAddrPort}/cli";
		$conn = curl_init($respEndpoint);
		if ($conn === false) {
			$this->error("Cannot conect to server at $respEndpoint");
		} else {
			curl_setopt($conn, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($conn, CURLOPT_POST, 1);
			curl_setopt($conn, CURLOPT_POSTFIELDS, $mntRequest);
			curl_setopt($conn, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
			curl_setopt($conn, CURLOPT_HTTPHEADER, ['X-Manticore-Error-Redirect: disable']);
			$resp = (string)curl_exec($conn);
			curl_close($conn);
		}
		return $resp;
	}

	/**
	 * @return bool
	 */
	protected function isMntResponseOk(): bool {
		$resp = json_decode($this->msg, true);
		if ($resp === null) {
			return false;
		}
		$resp = (array)$resp;
		return (array_key_exists('error', $resp) && $resp['error'] !== '') ? false : true;
	}

}

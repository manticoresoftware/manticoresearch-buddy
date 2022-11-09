<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use \Closure;
use Manticoresearch\Buddy\Enum\Action;
use Manticoresearch\Buddy\Enum\MntEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Enum\Statement;
use Manticoresearch\Buddy\Interface\BuddyLocatorClientInterface;
use Manticoresearch\Buddy\Interface\BuddyLocatorInterface;
use Manticoresearch\Buddy\Interface\ErrorQueryRequestInterface;
use Manticoresearch\Buddy\Interface\QueryParserLoaderInterface;
use Manticoresearch\Buddy\Interface\StatementInterface;
use \RuntimeException;

class ErrorQueryRequest implements ErrorQueryRequestInterface, BuddyLocatorClientInterface {

	const ACTION_MAP = [
		'NO_INDEX' => [
			'INSERT_QUERY' => [Action::CreateIndex, Action::Insert],
		],
		'UNKNOWN_COMMAND' => [
			'SHOW_QUERIES_QUERY' => [Action::SelectSystemSessions],
		],
	];

	/** @var string $origMsg */
	protected string $origMsg;

	/** @var string $query */
	protected string $query;

	/** @var RequestFormat $format */
	protected RequestFormat $format;

	/** @var RequestFormat $format */
	protected RequestFormat $queryFormat;

	/** @var MntEndpoint $endpoint */
	protected MntEndpoint $endpoint;

	/**
	 * List of Manticore statements to be used to execute original erroneous query
	 * @var array<StatementInterface> $correctionStmts
	 */
	protected array $correctionStmts;

	/**
	 * @param array{origMsg:string,query:string,format:RequestFormat,endpoint:MntEndpoint} $mntRequest
	 * @param ?BuddyLocatorInterface $locator
	 * @param ?QueryParserLoaderInterface $queryParserLoader
	 * @param ?StatementInterface $statementBuilder
	 * @return void
	 */
	protected function __construct(
		array $mntRequest,
		protected ?BuddyLocatorInterface $locator = null,
		protected ?QueryParserLoaderInterface $queryParserLoader = null,
		protected ?StatementInterface $statementBuilder = null
	) {
		foreach ($mntRequest as $k => $v) {
			$this->$k = $v;
		}
		// Resolve the possible ambiguity with Manticore query format as it may not correspond to request format
		$this->queryFormat = in_array($this->endpoint, [MntEndpoint::Cli, MntEndpoint::Sql])
		? RequestFormat::SQL : RequestFormat::JSON;
		$this->locateHelpers();
	}

	/**
	 * @param array{
	 * origMsg:string,query:string,format:RequestFormat,endpoint:MntEndpoint} $mntRequest
	 * @return ErrorQueryRequest
	 */
	public static function fromMntRequest(array $mntRequest): ErrorQueryRequest {
		return new self($mntRequest);
	}

	/**
	 * @return array<StatementInterface>
	 */
	public function getCorrectionStatements(): array {
		return $this->correctionStmts ?? [];
	}

	/**
	 * @return void
	 */
	public function generateCorrectionStatements(): void {
		if (!isset($this->statementBuilder)) {
			throw new RuntimeException('Statement builder is not instantiated');
		}
		$this->correctionStmts = [];
		$handleActions = $this->getHandleActions();
		if (empty($handleActions)) {
			return;
		}
		// Making the chain of needed statements to request Manticore
		$stmtData = array_map([$this, 'buildStmtDataByAction'], $handleActions);
		foreach ($stmtData as $stmt) {
			[
				'stmtBody' => $body,
				'stmtPostprocessor' => $postprocessor,
				'action' => $action,
			] = $stmt;
			$this->correctionStmts[] = $this->statementBuilder->create($body, $postprocessor, $action);
		}
	}

	/**
	 * @return string
	 */
	public function getOrigMsg(): string {
		return $this->origMsg;
	}

	/**
	 * @return MntEndpoint
	 */
	public function getEndpoint(): MntEndpoint {
		return $this->endpoint;
	}

	/**
	 * @param BuddyLocatorInterface $locator
	 * @return void
	 */
	public function setLocator(BuddyLocatorInterface $locator): void {
		$this->locator = $locator;
		$this->locateHelpers();
	}

	/**
	 * @return void
	 */
	protected function locateHelpers(): void {
		if (!isset($this->locator)) {
			return;
		}
		if (!isset($this->statementBuilder)) {
			$builder = $this->locator->getByInterface(StatementInterface::class);
			if ($builder instanceof StatementInterface) {
				$this->statementBuilder = $builder;
			}
		}
		if (isset($this->queryParserLoader)) {
			return;
		}
		$loader = $this->locator->getByInterface(QueryParserLoaderInterface::class);
		if (!($loader instanceof QueryParserLoaderInterface)) {
			return;
		}
		$this->queryParserLoader = $loader;
	}

	/**
	 * @param Action $action
	 * @return array{stmtBody:string,stmtPostprocessor:mixed,action:Action}
	 */
	protected function buildStmtDataByAction(Action $action): array {
		$stmtBody = match ($action) {
			Action::Insert => $this->query,
			Action::CreateIndex => $this->buildStmtWithParser(Statement::INSERT, Statement::CREATE),
			Action::SelectSystemSessions => 'SELECT * FROM @@system.sessions',
			default => '',
		};
		// Check if action has a postprocessing function
		$postprocessor = self::getStmtPostprocessor($action);

		return [
			'stmtBody' => $stmtBody,
			'stmtPostprocessor' => isset($postprocessor) ? [self::class, 'getStmtPostprocessor'] : null,
			'action' => $action,
		];
	}

	/**
	 * @param Action $action
	 * @return Closure|null
	 */
	public static function getStmtPostprocessor(Action $action): Closure|null {
		return match ($action) {
			Action::SelectSystemSessions => function (string $origResp) {
				$allowedFields = ['ID', 'query', 'host', 'proto'];
				$colNameMap = ['connid' => 'ID', 'last cmd' => 'query'];
				$resp = (array)json_decode($origResp, true);
				// Updating column names in 'data' field
				foreach ($colNameMap as $k => $v) {
					$resp[0] = (array)$resp[0];
					$resp[0]['data'] = (array)$resp[0]['data'];
					$resp[0]['data'][0] = (array)$resp[0]['data'][0];
					$resp[0]['data'][0][$v] = $resp[0]['data'][0][$k];
				}
				$resp[0]['data'][0] = array_filter(
					$resp[0]['data'][0],
					function ($k) use ($allowedFields) {
						return in_array($k, $allowedFields);
					},
					ARRAY_FILTER_USE_KEY
				);
				// Updating column names in 'columns' field
				$updatedCols = [];
				foreach ((array)$resp[0]['columns'] as $col) {
					$colKeys = array_keys((array)$col);
					$k = $colKeys[0];
					if (array_key_exists($k, $colNameMap)) {
						$updatedCols[] = [$colNameMap[$k] => $col[$k]];
					} elseif (in_array($k, $allowedFields)) {
						$updatedCols[] = $col;
					}
				}
				$resp[0]['columns'] = $updatedCols;
				// Replacing updated fields 'columns' and 'data' in the original response
				$replFrom = [
					'/(\[\{\n"columns":)(.*?)(,\n"data":\[)/s',
					'/(,\n"data":\[)(.*?)(\n\],\n)/s',
				];
				$replTo = [
					json_encode($resp[0]['columns']),
					json_encode($resp[0]['data'][0]),
				];
				$res = preg_replace_callback(
					$replFrom,
					function ($matches) use (&$replTo) {
						return $matches[1] . array_shift($replTo) . $matches[3];
					},
					$origResp
				);

				return $res ?? '';
			},
			default => null,
		};
	}

	/**
	 * @param Statement $inStmtType
	 * @param Statement $outStmtType
	 * @return string
	 */
	protected function buildStmtWithParser(Statement $inStmtType, Statement $outStmtType): string {
		if (!isset($this->queryParserLoader)) {
			throw new RuntimeException('Query parser loader is not instantiated');
		}
		switch ($inStmtType) {
			case Statement::INSERT:
				$parser = $this->queryParserLoader::getInsertQueryParser($this->queryFormat);
				break;
			default:
				throw new RuntimeException("Unsupported query type {$inStmtType->value}");
		}
		$parseData = $parser->parse($this->query);
		return match ($outStmtType) {
			Statement::CREATE => $this->buildCreateStmt(
				$parseData['name'], $parseData['cols'], $parseData['colTypes']
			),
			default =>  throw new RuntimeException("Unsupported statement type {$outStmtType->value}"),
		};
	}

	/**
	 * @param string $name
	 * @param array<string> $cols
	 * @param array<string> $colTypes
	 * @return string
	 */
	protected static function buildCreateStmt(string $name, array $cols, array $colTypes): string {
		$colExpr = implode(
			',',
			array_map(
				function ($a, $b) {
					return "$a $b";
				},
				$cols,
				$colTypes
			)
		);
		$repls = ['%NAME%' => $name, '%COL_EXPR%' => $colExpr];
		return strtr('CREATE TABLE IF NOT EXISTS %NAME% (%COL_EXPR%)', $repls);
	}

	/**
	 * Checking if request can be handled by Buddy
	 *
	 * @return array<Action>
	 */
	protected function getHandleActions(): array {
		$errorType = $this->detectErrorType();
		if (!isset(static::ACTION_MAP[$errorType])) {
			return [];
		}
		$queryType = $this->detectQueryType();
		return static::ACTION_MAP[$errorType][$queryType] ?? [];
	}

	/**
	 * @return string
	 */
	protected function detectErrorType(): string {
		// so far only use case with non-existing local index
		if (preg_match('/index (.*?) absent/', $this->origMsg)) {
			return 'NO_INDEX';
		}
		if (str_contains($this->origMsg, 'unexpected identifier')) {
			return 'UNKNOWN_COMMAND';
		}
		return '';
	}

	/**
	 * @return string
	 */
	protected function detectQueryType(): string {
		if (stripos($this->query, 'SHOW QUERIES') === 0) {
			return 'SHOW_QUERIES_QUERY';
		}
		if ($this->queryFormat === RequestFormat::SQL && stripos($this->query, 'INSERT') === 0) {
			return 'INSERT_QUERY';
		}
		if ($this->queryFormat === RequestFormat::JSON) {
			$queryHead = trim(substr($this->query, 1));
			if (stripos($queryHead, '"insert"') === 0 || stripos($queryHead, '"index"') === 0) {
				return 'INSERT_QUERY';
			}
		}
		return '';
	}
}

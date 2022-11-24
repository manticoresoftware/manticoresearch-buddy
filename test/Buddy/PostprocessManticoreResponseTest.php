<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Lib\ErrorQueryRequest;
use Manticoresearch\Buddy\Lib\ManticoreHTTPClient;
use Manticoresearch\Buddy\Lib\ManticoreResponseBuilder;
use Manticoresearch\Buddy\Lib\ManticoreStatement;
use Manticoresearch\Buddy\Lib\QueryParserLoader;
use Manticoresearch\Buddy\Network\Request;
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use PHPUnit\Framework\TestCase;

class PostprocessManticoreResponseTest extends TestCase {

	use TestHTTPServerTrait;

	public function testManticoreResponsePostprocess(): void {
		echo "\nTesting the postprocessing of Manticore response received\n";
		$respBody = "[{\n"
			. '"columns":[{"proto":{"type":"string"}},{"host":{"type":"string"}},'
			. '{"ID":{"type":"long long"}},{"query":{"type":"string"}}],'
			. "\n"
			. '"data":[{"proto":"http","host":"127.0.0.1:584","ID":19,"query":"select"}'
			. "\n],\n"
			. '"total":1,'
			. "\n"
			. '"error":"",'
			. "\n"
			. '"warning":""'
			. "\n}]";
		$request = Request::fromArray(
			[
				'origMsg' => "sphinxql: syntax error, unexpected identifier, expecting VARIABLES near 'QUERIES'",
				'query' => 'SHOW QUERIES',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$serverUrl = self::setUpMockManticoreServer(false);
		$manticoreClient = new ManticoreHTTPClient(new ManticoreResponseBuilder(), $serverUrl);
		$request = ErrorQueryRequest::fromNetworkRequest($request);
		$refCls = new ReflectionClass($request);
		$refCls->getProperty('queryParserLoader')->setValue($request, new QueryParserLoader());
		$refCls->getProperty('statementBuilder')->setValue($request, new ManticoreStatement());
		$request->generateCorrectionStatements();
		$statements = $request->getCorrectionStatements();
		$stmt = $statements[0];
		$resp = $manticoreClient->sendRequest($stmt->getBody());
		$processor = $stmt->getPostprocessor();
		if (isset($processor)) {
			$resp->postprocess($processor);
		}
		$this->assertEquals($respBody, $resp->getBody());
		self::finishMockManticoreServer();
	}

}

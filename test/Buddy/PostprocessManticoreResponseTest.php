<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\MntEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Lib\BuddyLocator;
use Manticoresearch\Buddy\Lib\ErrorQueryRequest;
// use Manticoresearch\Buddy\Lib\MntResponse;
use Manticoresearch\Buddy\Lib\MntHTTPClient;
// use Manticoresearch\BuddyTest\Lib\MockManticoreServer;
use Manticoresearch\Buddy\Lib\MntResponseBuilder;
use Manticoresearch\Buddy\Network\Request;
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use PHPUnit\Framework\TestCase;

class PostprocessManticoreResponseTest extends TestCase {

	use TestHTTPServerTrait;

	public function testMntResponsePostprocess(): void {
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
				'endpoint' => MntEndpoint::Cli,
			]
		);
		$serverUrl = self::setUpMockMntServer(false);
		$mntClient = new MntHTTPClient(new MntResponseBuilder(), $serverUrl);
		$request = ErrorQueryRequest::fromNetworkRequest($request);
		$request->setLocator(new BuddyLocator());
		$request->generateCorrectionStatements();
		$statements = $request->getCorrectionStatements();
		$stmt = $statements[0];
		$resp = $mntClient->sendRequest($stmt->getBody());
		$processor = $stmt->getPostprocessor();
		if (isset($processor)) {
			$resp->postprocess($processor);
		}
		$this->assertEquals($respBody, $resp->getBody());
		self::finishMockMntServer();
	}

}

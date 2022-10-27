<?php declare(strict_types=1);

namespace Manticoresearch\Buddy;

use Manticoresearch\Buddy\Exception\BuddyRequestError;
use Manticoresearch\Buddy\Exception\ParserLocationError;
use Manticoresearch\Buddy\Exception\SocketError;
use Manticoresearch\Buddy\Lib\Buddy;
use Manticoresearch\Buddy\Lib\QueryParserLocator;
use Manticoresearch\Buddy\Lib\SocketHandler;
use Manticoresearch\Buddy\Lib\StatementBuilder;
use RuntimeException;
use Throwable;

$longopts  = [
	'pid:',
	'pid_path:',
	'listen:',
	'sockAddr::',
	'sockPort::',
];
$defaultOpts = [
	'sockAddr' => '127.0.0.1',
	'sockPort' => 0,
];
/** @var string $clPid */
/** @var string $clPidPath */
/** @var string $clAddrPort */
/** @var string $sockAddr */
/** @var string $sockPort */
[
	'pid' => $clPid,
	'pid_path' => $clPidPath,
	'listen' => $clAddrPort,
	'sockAddr' => $sockAddr,
	'sockPort' => $sockPort,
] = getopt('', $longopts) + $defaultOpts;

$sockPort = (int)$sockPort;
$socketHandler = new SocketHandler($sockAddr, $sockPort, new SocketError());
$parserLocator = new QueryParserLocator(new ParserLocationError());
$buddy = new Buddy($clAddrPort, $parserLocator, new StatementBuilder(), new BuddyRequestError());

// listening socket in loop
while (true) {
	if ($socketHandler->hasMsg() === false) {
		if (Buddy::isClientAlive($clPid, $clPidPath) === false) {
			die('Client is dead');
		}
		usleep(1000);
	} else {
		try {
			$req = $socketHandler->readMsg();

			foreach ($req as $k => $v) {
				$req[$k] = strval($v);
			}
			[
				'type' => $errorMsg,
				'message' => $query,
				'reqest_type' => $format,
			] = $req;

			$resp = $buddy->getResponse($errorMsg, $query, $format);
			$socketHandler->writeMsg($resp);
		} catch (Throwable $e) {
			$resp = $buddy->buildResponse(message: '', error: $e->getMessage());
			try {
				$socketHandler->writeMsg($resp);
			} catch (RuntimeException $e) {
			}
		}
	}
}

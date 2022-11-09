<?php declare(strict_types=1);

namespace Manticoresearch\Buddy;

use Manticoresearch\Buddy\BuddyTestLoop;
use Manticoresearch\Buddy\Lib\SocketHandler;

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
/** @var string $clUrl */
/** @var string $sockAddr */
/** @var string $sockPort */
[
	'pid' => $clPid,
	'pid_path' => $clPidPath,
	//'listen' => $clUrl,
	'sockAddr' => $sockAddr,
	'sockPort' => $sockPort,
] = getopt('', $longopts) + $defaultOpts;

$sockPort = (int)$sockPort;

$buddyLoop = new BuddyTestLoop(new SocketHandler($sockAddr, $sockPort), $clPid, $clPidPath);
$buddyLoop->start();

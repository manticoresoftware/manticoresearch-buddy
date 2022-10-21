<?php declare(strict_types=1);

define('SEARCHD_HANDSHAKE', 4);
define('SEARCHD_HEADER', 8);
define('SEARCHD_PROTO', 1);

/**
 * @param string $msg
 * @return void
 */
function send_die_msg(string $msg): void {
	die("$msg: " . socket_strerror(socket_last_error()) . "\n");
}

/**
 * @param string $field
 * @return string
 */
function b2h(string $field): string {
	$res = bin2hex($field);
	$res = chunk_split($res, 2, ' 0x');
	$res = ' 0x' . substr($res, 0, -2);

	return $res;
}

/**
 * @param Socket $socket
 * @param int $dataLen
 * @return false|string
 */
function read_socket_data(Socket $socket, int $dataLen): false|string {
	$data = '';
	$dataGot = socket_recv($socket, $data, $dataLen, MSG_WAITALL) or send_die_msg('Cannot read from the socket');

	if ($dataGot !== $dataLen) {
		// !COMMIT
		// echo $dataGot . "\n";
		// var_dump ( b2h ( $data ) );
		echo 'invalid format, got data length ' . $dataGot . ' expected ' . $dataLen;
		return false;
	}

	return $data;
}

/**
 * @param Socket $socket
 * @return false|array<string>
 */
function read_socket_msg(Socket $socket): false|array {
	$data = read_socket_data($socket, SEARCHD_HANDSHAKE);
	if ($data === false) {
		return false;
	}

	$data = read_socket_data($socket, SEARCHD_HEADER);
	if ($data === false) {
		return false;
	}

	$unpacked = unpack('ncmd/nver/Nlen', $data);
	if ($unpacked === false) {
		return false;
	}
	[, , $msgLen] = array_values($unpacked);

	$req = read_socket_data($socket, $msgLen);
	if ($req === false) {
		return false;
	}

	// // !COMMIT
	// echo $msgLen . " "  . $cmd . " "  . $ver . "\n";
	// echo ( b2h ( $data ) ) . "\n";
	// var_dump ( $req );
	// $r = $data . $req;
	// var_dump ( $r );

	$result = json_decode($req, true);
	if (!$result) {
		return false;
	}

	/** @var array<string> $result */
	return $result;
}

/**
 * @param Socket $socket
 * @param array{type:string,message:string,endpoint:string} $msgData
 * @return void
 */
function write_socket_msg(Socket $socket, array $msgData): void {
	$msg = json_encode($msgData);
	if (false === $msg) {
		return;
	}
	$msgLen = strlen($msg);
	$data = pack('N', $msgLen) . $msg;
	$dataLen = strlen($data);

	// PROTO, SEARCHD_OK, ver, data length
	$packet = pack('NnnN', SEARCHD_PROTO, 0, 0, $dataLen) . $data;

	echo $msgLen . ' ' . $dataLen  . ' ' . strlen($packet) . ', reply:' . $msg . "\n";

	socket_write($socket, $packet, strlen($packet)) or send_die_msg('Cannot write to the socket');
}

/**
 * @param string $string
 * @return string
 */
function escape(string $string): string {
	$from = ['\\', '(',')','|','-','!','@','~','"','&', '/', '^', '$', '=', '<'];
	$to = ['\\\\', '\(','\)','\|','\-','\!','\@','\~','\"', '\&', '\/', '\^', '\$', '\=', '\<'];
	return str_replace($from, $to, $string);
}

// checking if client's process (i.e. Manticore daemon) is alive
/**
 * @param string $pid
 * @param string $pidPath
 * @return bool
 */
function check_client(string $pid, string $pidPath): bool {
	$pidFromFile = -1;
	if (file_exists($pidPath)) {
		$content = file_get_contents($pidPath);
		if ($content === false) {
			return false;
		}
		$pidFromFile = substr($content, 0, -1);
	}
	return $pid === $pidFromFile;
}

/**
 * @return Socket
 */
function setup(): Socket {
	$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or send_die_msg('Cannot create a socket');
	if ($socket === false) {
		die('Cannot create the socket');
	}
	$longopts  = [
		'addr::',
		'port::',
	];
	$options = getopt('', $longopts);

	$addr = '127.0.0.1';
	$port = 0;

	if (array_key_exists('addr', $options)) {
		$addr = $options['addr'];
	}

	if (array_key_exists('port', $options)) {
		$port = (int)$options['port'];

		if (!socket_bind($socket, '127.0.0.1', $port) || !socket_getsockname($socket, $addr, $port)) {
			die('Cannot bind to a defined port, addr ' . $addr . ' port ' . $port);
		}
	}

	$retryCount = 10;
	//trying to bind to any port available on the host machine
	while ($port === 0 && $retryCount) {
		socket_bind($socket, '127.0.0.1', 0);
		socket_getsockname($socket, $addr, $port);
		sleep(1);
		$retryCount--;
	}

	/** @var int $port */
	if (!$port) {
		die("Cannot find a port available, addr $addr port $port");
	}

	socket_listen($socket) or send_die_msg('Cannot listen to the socket created');
	socket_set_nonblock($socket);

	// outputting connection info for the Manticore server to get it
	echo "started $addr:$port\n";

	return $socket;
}

// Moved from buddy.php due to we should not mix declaration with logic
/**
 * @param string $errorMsg
 * @return false|string
 */
function detect_error_type(string $errorMsg): false|string {
	// so far only use case with non-existing local index
	if (preg_match('/index (.*?) absent/', $errorMsg)) {
		return 'NO_INDEX';
	}

	if (strpos($errorMsg, 'unexpected identifier') !== false) {
		return 'UNKNOWN_COMMAND';
	}
	return false;
}

/**
 * @param string $query
 * @return string|false
 */
function detect_query_type(string $query): string|false {
	if (stripos($query, 'INSERT') === 0) {
		return 'INSERT_QUERY';
	}

	if (stripos($query, 'SHOW QUERIES') === 0) {
		return 'SHOW_QUERIES_QUERY';
	}
	return false;
}

// splitting VALUES expression into separate row values
/**
 * @param string $valExpr
 * @return array<string>
 */
function parse_insert_val_expr(string $valExpr): array {
	$vals = [];
	$curVal = '';
	$parenthInd = 0;
	$isValStarted = false;
	for ($i = 0; $i < strlen($valExpr); $i++) {
		switch ($valExpr[$i]) {
			case '(':
				if (!$isValStarted) {
					$isValStarted = true;
				} else {
					$curVal .= '(' ;
				}
				$parenthInd++;
				break;
			case ')':
				$parenthInd--;
				if (!$parenthInd && $valExpr[$i - 1] !== '\\') {
					$vals[] = $curVal;
					$isValStarted = false;
					$curVal = '';
				} else {
					$curVal .= ')' ;
				}
				break;
			default:
				if ($isValStarted) {
					$curVal .= $valExpr[$i];
				}
				break;
		}
	}
	return $vals;
}

// temporarily replacing column values that can contain commas to tokens before splitting expression
/**
 * @param string $row
 * @return array<string,string[]>
 */
function replace_comma_blocks(string &$row): array {
	$blocksReplaced = [];
	$replInfo = [
		[ '{', '}' ],
		[ '\(', '\)' ],
		[ "'", "'" ],
	];
	foreach ($replInfo as $i => $replItem) {
		$repl = "%$i";
		while (strpos($row, $repl) !== false) {
			$repl = "%$repl";
		}
		$pattern = "/($replItem[0].*?[^\\\]$replItem[1])/i";
		preg_match_all($pattern, $row, $matches);
		if (empty($matches[0])) {
			continue;
		}

		$row = preg_replace($pattern, $repl, $row);
		if (!is_string($row)) {
			throw new Exception('Something went wrong');
		}
		$blocksReplaced[$repl] = $matches[0];
	}

	return $blocksReplaced;
}

// getting replaced column values back
/**
 * @param array<string,string[]> $blocksReplaced
 * @param string[] $insertVals
 * @return void
 */
function restore_comma_blocks(array $blocksReplaced, array &$insertVals): void {
	do {
		$replExist = false;
		foreach (array_keys($blocksReplaced) as $k) {
			if (empty($blocksReplaced[$k])) {
				continue;
			}

			foreach ($insertVals as $i => $val) {
				if (strpos($val, $k) === false) {
					continue;
				}

				/** @var string $repl */
				$repl = array_shift($blocksReplaced[$k]);
				$insertVals[$i] = str_replace($k, $repl, $val);
				$replExist = true;
			}
		}
	} while ($replExist);
}

// splitting row values expression into separate values
/**
 * @param string $row
 * @return string[]
 */
function parse_insert_row(string $row): array {
	$blocksReplaced = replace_comma_blocks($row);
	$insertVals = explode(',', $row);
	restore_comma_blocks($blocksReplaced, $insertVals);
	foreach ($insertVals as $i => $val) {
		$insertVals[$i] = trim($val);
	}

	return $insertVals;
}


// detecting the probable datatype of column or datatype error
/**
 * @param string $val
 * @return string
 */
function detect_val_type(string $val): string {
	// numeric types
	if (is_numeric($val)) {
		$int = (int)$val;
		if ((string)$int !== $val) {
			return 'float';
		}

		if ($int > 2147483647) {
			return 'bigint';
		}

		return 'int';
	}
	// json type
	if (substr($val, 0, 1) === '{' && substr($val, -1) === '}') {
		return 'json';
	}
	// mva types
	if (substr($val, 0, 1) === '(' && substr($val, -1) === ')') {
		$subVals = explode(',', substr($val, 1, -1));
		array_walk(
			$subVals, function (&$v) {
				return trim($v);
			}
		);
		foreach ($subVals as $v) {
			if (detect_val_type($v) === 'bigint') {
				return 'multi64';
			}
		}
		return 'multi';
	}
	// determining if type is text or string, using Elastic's logic
	$regexes = [
		// so far only email regex is implemented for the prototype
		'email' => '/^\s*(?:[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+'
			. '(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*|"'
			. '(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|'
			. '\\[\x01-\x09\x0b\x0c\x0e-\x7f])*")\\\@'
			. '(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+'
			. '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:(2(5[0-5]|[0-4][0-9])|1[0-9][0-9]|[1-9]?[0-9]))\.){3}'
			. '(?:(2(5[0-5]|[0-4][0-9])|1[0-9][0-9]|[1-9]?[0-9])|[a-z0-9-]*'
			. '[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|'
			. '\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)\])\s*$/i',
	];
	foreach ($regexes as $r) {
		if (preg_match($r, substr($val, 0, -1))) {
			return 'string';
		}
	}
	return 'text';
}

// checking for incompatible column datatypes in different rows
/**
 * @param array<string> $curTypes
 * @param array<string> &$types
 * @param array<string> $cols
 * @param array{error?:string} &$errorInfo
 * @return void
 */
function check_col_types_compatibility(array $curTypes, array &$types, array $cols, array &$errorInfo): void {
	foreach ($curTypes as $i => $t) {
		foreach (TYPE_BUNDLES as $tb) {
			$i1 = array_search($t, $tb);
			$i2 = array_search($types[$i], $tb);
			if (($i1 === false and $i2) or ($i2 === false and $i1)) {
				if (!isset($errorInfo['error'])) {
					$errorInfo['error'] = "Incompatible data types for columns $cols[$i] $t $types[$i]";
				} else {
					$errorInfo['error'] .= ", $cols[$i]";
				}
			}
			// updating possible datatype by priority
			if ($i1 >= $i2) {
				continue;
			}
			$types[$i] = $tb[$i1];
		}
	}
}

// checking potentially ambiguous datatypes and datatype errors
/**
 * @param array<string> $curTypes
 * @param array<string> &$types
 * @param array<string> $cols
 * @param array{error?:string} &$errorInfo
 */
function check_col_types(array $curTypes, array &$types, array $cols, array &$errorInfo): void {
	if (!empty($types)) {
		// checking for column count in different rows
		if (sizeof($curTypes) !== sizeof($types) or sizeof($curTypes) !== sizeof($cols)) {
			$errorInfo['error'] = 'Column count mismatch in INSERT statement';
		} else {
			check_col_types_compatibility($curTypes, $types, $cols, $errorInfo);
		}
	} else {
		$types = $curTypes;
	}
}

// extracting table name, column names and datatypes from INSERT statement
/**
 * @param string $query
 * @return array{name:string,cols:array<string>,colTypes:array<string>,error?:string}
 */
function parse_insert_stmt(string $query): array {
	$matches = [];
	preg_match_all('/\s*INSERT\s+INTO\s+(.*?)\s*\((.*?)\)\s+VALUES\s*(.*?)\s*;?\s*$/i', $query, $matches);
	$name = $matches[1][0];
	$colExpr = $matches[2][0];
	$cols = explode(',', $colExpr);
	$valExpr = $matches[3][0];

	$rows = parse_insert_val_expr($valExpr);
	$colTypes = $errorInfo = [];

	foreach ($rows as $row) {
		$rowVals = parse_insert_row($row);
		$curColTypes = array_map('detect_val_type', $rowVals);
		check_col_types($curColTypes, $colTypes, $cols, $errorInfo);
		if (!empty($errorInfo)) {
			return $errorInfo;
		}
	}

	return ['name' => $name, 'cols' => $cols, 'colTypes' => $colTypes];
}

/**
 * @param string $response
 * @return string
 */
function encode_response(string $response): string {
	return 'query=' . str_replace('+', '%20', urlencode($response));
}

/**
 * @param string $action
 * @param string $query
 * @return false|array{type:string,message:string}
 */
function build_resp_content(string $action, string $query): false|array {
	switch ($action) {
		case 'CREATE_INDEX':
			$res = parse_insert_stmt($query);
			if (array_key_exists('error', $res)) {
				return ['type' => 'http response', 'message' => $res['error']];
			}
			['name' => $name, 'cols' => $cols, 'colTypes' => $colTypes] = parse_insert_stmt($query);
			$colExpr = implode(
				',', array_map(
					function ($a, $b) {
						return "$a $b";
					}, $cols, $colTypes
				)
			);
			$repls = ['%NAME%' => $name, '%COL_EXPR%' => $colExpr];
			$resp = strtr('CREATE TABLE IF NOT EXISTS %NAME% (%COL_EXPR%)', $repls);
			return ['type' => 'execute and return', 'message' => $resp ];
		case 'SELECT_SYSTEM_SESSIONS':
			$resp = 'SELECT connid AS ID, `last cmd` AS query, host, proto FROM @@system.sessions)';
			return ['type' => 'execute and return', 'message' => $resp ];
		default:
			return false;
	}
}


// checking if request can be handled by Buddy
/**
 * @param string $errorMsg
 * @param string $query
 * @return false|string
 */
function get_handle_action(string $errorMsg, string $query): false|string {
	$errorType = detect_error_type($errorMsg);
	file_put_contents('/tmp/test.txt', "3-$errorType\n", FILE_APPEND);
	if (isset(ACTION_MAP[$errorType])) {
		$queryType = detect_query_type($query);
		file_put_contents('/tmp/test.txt', "4-$queryType\n", FILE_APPEND);
		return ACTION_MAP[$errorType][$queryType] ?? false;
	}
	return false;
}


/**
 * @param string $action
 * @return false|string
 */
function get_resp_uri(string $action): false|string {
	foreach (ENDPOINT_MAP as $k => $v) {
		if (in_array($action, $v)) {
			return $k;
		}
	}
	return false;
}

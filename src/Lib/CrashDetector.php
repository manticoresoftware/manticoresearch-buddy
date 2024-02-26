<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Lib;

use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;

class CrashDetector {
	// Chunk size we use to read for one
	const READ_BUFFER_BYTES = 4096;

	// How many bytes we look from the end of the last log
	const MAX_READ_BYTES = 16384;

	/**
	 * Initialize crash detector by using manticore settings
	 * @param Settings $settings
	 * @return void
	 */
	public function __construct(protected Settings $settings) {
	}

	/**
	 * The method check searchd.log and try to find if we crashed last time
	 * and send metric that increments crash counter
	 * @return bool
	 * 	we return false even when we cannot detect that we had crash
	 */
	public function hadCrash(): bool {
		// If we have no log set, skip it
		if (!$this->settings->searchdLog) {
			return false;
		}

		if (!file_exists($this->settings->searchdLog)) {
			return false;
		}

		// Let's parse searchd.log now and find out last crash
		$fp = fopen($this->settings->searchdLog, 'rb');
		if (!$fp) {
			return false;
		}
		$hadCrash = static::hadCrashInLog($fp);
		fclose($fp);

		return $hadCrash;
	}

	/**
	 * Get resource to the file and read it from the end to try to find crash log
	 * @param mixed $fp
	 * @return bool
	 */
	protected static function hadCrashInLog(mixed $fp): bool {
		/** @var resource $fp */
		$hadCrash = false;
		fseek($fp, 0, SEEK_END);
		$pos = max(0, ftell($fp) - static::READ_BUFFER_BYTES);
		$starts = 0;
		$readBytes = 0;
		$partial = '';
		while ($pos > 0) {
			fseek($fp, $pos);
			$buffer = fread($fp, static::READ_BUFFER_BYTES) . $partial;
			if (!$buffer) {
				break;
			}
			$pos -= static::READ_BUFFER_BYTES;
			[$lines, $partial] = static::parseBuffer($buffer);
			foreach ($lines as $line) {
				// If we are in proper log and reached max bytes to read
				// But still no crash found, we should break the loop and stop
				if ($starts >= 1) {
					$readBytes += strlen($line);
				}

				[$starts, $hadCrash] = static::validateLogLine($line, $starts);
				if (!$hadCrash && $starts < 2 && $readBytes < static::MAX_READ_BYTES) {
					continue;
				}

				goto end;
			}
		}
		end: return $hadCrash;
	}

	/**
	 * Get lines and rest from buffer
	 * @param string $buffer
	 * @return array{0:array<string>,1:string}
	 */
	protected static function parseBuffer(string $buffer): array {
		$lines = explode(PHP_EOL, $buffer);
		if ($buffer[0] === PHP_EOL) {
			$partial = '';
		} else {
			$partial = $lines[0];
			unset($lines[0]);
		}
		return [array_reverse($lines), $partial];
	}

	/**
	 * We just split in small functions just because of cognitive complexity is high
	 * This function just look into line and detect if we had crash and return info
	 * @param string $line
	 * @param int $starts
	 * @return array{0:int,1:bool}
	 *  array with values of starts and hadCrash
	 */
	protected static function validateLogLine(string $line, int $starts): array {
		// We keep it to look for 2nd one, because first one
		// from the end of the file – is current daemon launch process
		if (str_contains($line, 'starting daemon version')) {
			++$starts;
		}

		// Try find crash in lines last launch belongs to
		// We also can stop when we found crash
		// @see: https://github.com/manticoresoftware/manticoresearch/blob/master/src/searchd.cpp#L960
		// We are checking from the end, so check ending of crash that looks like:
		// --- crashed XXX request dump ---
		// …
		// backtrace
		// --- request dump end ---
		if ($starts === 1 && str_contains($line, 'CRASH DUMP END')) {
			return [$starts, true];
		}

		return [$starts, false];
	}
}

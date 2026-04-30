<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Lib;

// PHP's streamWrapper interface fixes both the method names (snake_case) and
// the signatures, so several parameters here are required but unused.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
// phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter

/**
 * Spy stream wrapper for https:// that captures the stream context options
 * passed to file_get_contents() by Manticoresoftware\Telemetry\Metric::process().
 * Returns an empty body so the library treats the call as a successful POST.
 */
final class TelemetrySpyHttpsWrapper {
	/** @var array<mixed> */
	public static array $contextOptions = [];
	public static string $lastUrl = '';

	/** @var resource|null */
	public $context;
	private bool $consumed = false;

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		self::$lastUrl = $path;
		self::$contextOptions = is_resource($this->context) ? stream_context_get_options($this->context) : [];
		return true;
	}

	public function stream_read(int $count): string {
		if ($this->consumed) {
			return '';
		}
		$this->consumed = true;
		return '';
	}

	public function stream_eof(): bool {
		return $this->consumed;
	}

	/** @return array<int|string,int> */
	public function stream_stat(): array {
		return [];
	}

	public function stream_close(): void {
	}
}

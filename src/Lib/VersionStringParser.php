<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Lib;

final class VersionStringParser {

	/**
	 * Parses version string from SHOW STATUS like 'version'.
	 *
	 * Example:
	 *   15.1.0 b13699a92@25120511 (columnar 9.0.0 b7e9e68@25113006) (embeddings 1.0.1) (buddy v3.40.2+...-g27a2f87e)
	 *
	 * @return array{
	 *   daemon:string,
	 *   groups:array<int, array{raw:string,name:string,ver:string,build:string}>
	 * }
	 */
	public static function parse(string $value): array {
		$value = trim($value);
		if ($value === '') {
			return ['daemon' => '', 'groups' => []];
		}

		$daemon = self::extractDaemon($value);

		$groups = self::extractGroups($value);

		return [
			'daemon' => $daemon,
			'groups' => $groups,
		];
	}

	private static function extractDaemon(string $value): string {
		$pos = strpos($value, '(');
		if ($pos === false) {
			return $value;
		}

		return trim(substr($value, 0, $pos));
	}

	/**
	 * @return array<int, array{raw:string,name:string,ver:string,build:string}>
	 */
	private static function extractGroups(string $value): array {
		$matchesCount = preg_match_all('/\\(([^)]+)\\)/', $value, $matches);
		if ($matchesCount === false || $matchesCount === 0) {
			return [];
		}

		$groups = [];
		foreach ($matches[1] as $rawMatch) {
			$group = self::parseGroup((string)$rawMatch);
			if ($group === null) {
				continue;
			}

			$groups[] = $group;
		}

		return $groups;
	}

	/**
	 * @return array{raw:string,name:string,ver:string,build:string}|null
	 */
	private static function parseGroup(string $value): ?array {
		$raw = trim($value);
		if ($raw === '') {
			return null;
		}

		$parts = preg_split('/\\s+/', $raw);
		if (!is_array($parts) || $parts === []) {
			return null;
		}

		$name = $parts[0] ?? '';
		if (!is_string($name) || $name === '') {
			return null;
		}

		$ver = $parts[1] ?? '';
		if (!is_string($ver)) {
			$ver = '';
		}

		$build = $parts[2] ?? '';
		if (!is_string($build)) {
			$build = '';
		}

		return [
			'raw' => $raw,
			'name' => $name,
			'ver' => $ver,
			'build' => $build,
		];
	}
}

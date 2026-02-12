<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics;

use Manticoresearch\Buddy\Base\Lib\VersionStringParser;

final class BuildInfoParser {

	/**
	 * @return array<string, string>
	 */
	public static function parseLabels(string $version): array {
		$version = trim($version);
		if ($version === '') {
			return [];
		}

		$parsed = VersionStringParser::parse($version);

		$labels = self::getEmptyLabels();
		self::fillDaemonBuildInfo($labels, $parsed['daemon']);
		self::fillComponentBuildInfo($labels, $parsed['groups']);
		return $labels;
	}

	/**
	 * @return array<string, string>
	 */
	private static function getEmptyLabels(): array {
		return [
			'daemon_semver' => '',
			'daemon_commit' => '',
			'columnar_semver' => '',
			'columnar_commit' => '',
			'secondary_semver' => '',
			'secondary_commit' => '',
			'knn_semver' => '',
			'knn_commit' => '',
			'embeddings_semver' => '',
			'embeddings_commit' => '',
			'buddy_semver' => '',
			'buddy_commit' => '',
		];
	}

	/**
	 * @param array<string, string> $labels
	 */
	private static function fillDaemonBuildInfo(array &$labels, string $version): void {
		if (preg_match('/^([^ ]+)\\s+([^ ]+)/', $version, $matches) !== 1) {
			return;
		}

		$labels['daemon_semver'] = self::extractSemver($matches[1]);
		$labels['daemon_commit'] = self::extractCommit($matches[2]);
	}

	/**
	 * @param array<string, string> $labels
	 * @param array<int, array{raw:string,name:string,ver:string,build:string}> $groups
	 */
	private static function fillComponentBuildInfo(array &$labels, array $groups): void {
		foreach ($groups as $group) {
			$name = $group['name'];
			$ver = $group['ver'];
			$build = $group['build'];

			if ($ver === '' && $build === '') {
				continue;
			}

			if (str_starts_with($ver, 'v')) {
				$ver = substr($ver, 1);
			}

			self::applyComponentLabels($labels, $name, $ver, $build);
		}
	}

	/**
	 * @param array<string, string> $labels
	 */
	private static function applyComponentLabels(array &$labels, string $name, string $ver, string $build): void {
		switch ($name) {
			case 'columnar':
				$labels['columnar_semver'] = self::extractSemver($ver);
				$labels['columnar_commit'] = self::extractCommit($build);
				break;
			case 'secondary':
				$labels['secondary_semver'] = self::extractSemver($ver);
				$labels['secondary_commit'] = self::extractCommit($build);
				break;
			case 'knn':
				$labels['knn_semver'] = self::extractSemver($ver);
				$labels['knn_commit'] = self::extractCommit($build);
				break;
			case 'embeddings':
				$labels['embeddings_semver'] = self::extractSemver($ver);
				$labels['embeddings_commit'] = self::extractCommit($build);
				break;
			case 'buddy':
				$labels['buddy_semver'] = self::extractSemver($ver);
				$labels['buddy_commit'] = self::extractBuddyCommit($ver);
				break;
		}
	}

	private static function extractSemver(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		$matchesCount = preg_match('/\\d+\\.\\d+\\.\\d+/', $value, $matches);
		if ($matchesCount !== 1) {
			return $value;
		}

		return $matches[0];
	}

	private static function extractCommit(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		$hash = $value;
		if (str_contains($hash, '@')) {
			[$hash] = explode('@', $hash, 2);
		}

		$matchesCount = preg_match('/[0-9a-f]{6,40}/i', $hash, $matches);
		if ($matchesCount !== 1) {
			return '';
		}

		return substr($matches[0], 0, 8);
	}

	private static function extractBuddyCommit(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		$matchesCount = preg_match('/-g([0-9a-f]{6,40})/i', $value, $matches);
		if ($matchesCount !== 1) {
			return '';
		}

		return substr($matches[1], 0, 8);
	}
}

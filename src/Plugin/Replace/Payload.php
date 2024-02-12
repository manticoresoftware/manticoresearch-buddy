<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Replace;

use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 */
final class Payload extends BasePayload
{
	public string $path;

	public string $table;
	/** @var array <string, string> */
	public array $set;
	public int $id;
	public string $type;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enables partial replaces';
	}

	/**
	 * @param  Request  $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$self->path = $request->path;
		$self->type = $request->format->value;

		if ($request->format->value === RequestFormat::SQL->value) {
			preg_match(
				'/replace\s+into\s+`?(.*?)`?\s+set\s+(.*?)\s+where\s+id\s*=\s*([0-9]+)/usi',
				$request->payload,
				$matches
			);

			$self->table = $matches[1] ?? '';
			$self->set = self::parseSet($matches[2] ?? '');
			$self->id = (int)$matches[3];
		} else {
			$pathChunks = explode('/', $request->path);
			$payload = json_decode($request->payload, true);


			$self->table = $pathChunks[0] ?? '';
			$self->id = (int)$pathChunks[2];

			if (is_array($payload)) {
				$self->set = $payload['doc'] ?? [];
			}
		}

		return $self;
	}

	/**
	 * @param  Request  $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {

		if ($request->format->value === RequestFormat::SQL->value) {
			return (stripos($request->payload, 'replace') !== false &&
				stripos($request->payload, 'set') !== false);
		}

		return str_contains($request->path, '/_update/');
	}

	/**
	 * @param  string  $setStatement
	 * @return array <string, string>
	 */
	public static function parseSet(string $setStatement): array {
		$result = [];
		if (preg_match_all('/(?:\'[^\']*\'|"[^"]*"|`[^`]*`|\([^)]*\)|[^,])+/usi', $setStatement, $matches)) {
			if (isset($matches[0])) {
				foreach ($matches[0] as $part) {
					$groupMatches = [];
					preg_match('/`?(.*?)`?\s*=\s*[\'"]?(.*?)[\'"]?$/usi', trim($part), $groupMatches);

					if (!isset($groupMatches[1])) {
						continue;
					}

					$result[$groupMatches[1]] = $groupMatches[2];
				}
			}
		}

		return $result;
	}
}

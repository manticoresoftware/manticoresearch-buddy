<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\CreateCluster;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * @extends BasePayload<array>
 */
final class Payload extends BasePayload {
	public string $query;
	public bool $quiet = false;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enable CREATE CLUSTER IF NOT EXISTS statements';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();

		$payload = $request->payload;
		$self->query = $payload;
		if (preg_match('/IF\s+NOT\s+EXISTS/ius', $payload)) {
			$self->query = preg_replace('/\s+IF\s+NOT\s+EXISTS/ius', '', $payload) ?: $payload;
			$self->quiet = true;
		}

		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 * @throws GenericError
	 */
	public static function hasMatch(Request $request): bool {
		return $request->command === 'create'
			&& stripos($request->payload, 'create cluster') === 0
			&& stripos($request->error, 'P03') !== false;
	}
}

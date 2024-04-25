<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Plugin;

use Exception;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	public string $path;

	public ?string $package;
	public ?string $version;

	public function __construct(public ActionType $type) {
	}

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Core logic for plugin support and helpers.'
			. ' Also handles `create buddy plugin`, `delete buddy plugin`,'
			. ' and `show buddy plugins`';
	}

	/**
	 * @param Request $request
	 * @return static
	 * @throws QueryParseError
	 */
	public static function fromRequest(Request $request): static {
		$self = new static(static::getActionType($request->payload));
		switch ($self->type) {
			// We got create plugin request
			case ActionType::Create:
				$regex = "/^CREATE (?:BUDDY )?PLUGIN (\S+)(?: TYPE 'buddy')?( VERSION '(\S+)')?$/ius";
				if (!preg_match($regex, $request->payload, $matches)) {
					throw new QueryParseError('Failed to parse query');
				}

				$self->package = $matches[1];
				$self->version = $matches[3] ?? null;
				break;

			// We got delete buddy plugin query
			case ActionType::Delete:
				$regex = '/^DELETE BUDDY PLUGIN (\S+)$/ius';

				if (!preg_match($regex, $request->payload, $matches)) {
					throw new QueryParseError('Failed to parse query');
				}
				$self->package = $matches[1];
				break;

			// We got show buddy plugins
			case ActionType::Show:
				// Actually we should do nothing in this case and nothing to parse from query
				break;
			// We got disable buddy plugin query
			case ActionType::Disable:
			case ActionType::Enable:
				$regex = '/^(?:DISABLE|ENABLE) BUDDY PLUGIN (\S+)$/ius';

				if (!preg_match($regex, $request->payload, $matches)) {
					throw new QueryParseError('Failed to parse query');
				}
				$self->package = $matches[1];
				break;
		}

		$self->path = $request->path;
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		return stripos($request->payload, 'create plugin') === 0
			|| stripos($request->payload, 'create buddy plugin') === 0
			|| stripos($request->payload, 'delete buddy plugin') === 0
			|| strtolower($request->payload) === 'show buddy plugins'
			|| stripos($request->payload, 'disable buddy plugin') === 0
			|| stripos($request->payload, 'enable buddy plugin') === 0;
	}

	/**
	 * Helper to get ActionType enum value from the query
	 * @param string $query
	 * @return ActionType
	 * @throws Exception
	 */
	protected static function getActionType(string $query): ActionType {
		return match (strtok(strtolower($query), ' ')) {
			'create' => ActionType::Create,
			'show' => ActionType::Show,
			'delete' => ActionType::Delete,
			'disable' => ActionType::Disable,
			'enable' => ActionType::Enable,
			default => throw new Exception("Failed to detect action type from query: $query"),
		};
	}
}

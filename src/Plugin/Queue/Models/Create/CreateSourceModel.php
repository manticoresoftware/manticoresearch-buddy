<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Create;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Tool\SqlQueryParser;

/**
 * @template T of array
 * @extends Model<T>
 */
class CreateSourceModel extends Model {

	/**
	 * @throws GenericError
	 */
	public function getHandlerClass(): string {
		return $this->parseSourceType();
	}


	/**
	 * @throws GenericError
	 */
	public function parseSourceType(): string {
		foreach ($this->getPayload()['SOURCE']['options'] as $option) {
			if (isset($option['sub_tree'][0]['base_expr'])
				&& $option['sub_tree'][0]['base_expr'] === 'type') {
				return match (SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr'])) {
					'kafka' => 'Handlers\\Source\\CreateKafka',
					default => throw new GenericError('Cannot find handler for request type: ' . static::class)
				};
			}
		}
		throw new GenericError('Cannot find handler for request type: ' . static::class);
	}
}

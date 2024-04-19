<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Create;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Tool\SqlQueryParser;

class CreateSourceModel extends Model
{

	/**
	 * @throws GenericError
	 */
	#[\Override] public function getHandlerClass(): string {
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
					'kafka' => 'Handlers\\Source\\CreateKafka'
				};
			}
		}
		throw new GenericError('Cannot find handler for request type: ' . static::class);
	}
}

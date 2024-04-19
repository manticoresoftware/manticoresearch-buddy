<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Exception;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\SqlModelsHandler;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 */
final class Payload extends BasePayload
{

	const SOURCE_TABLE_NAME = '_sources';
	const VIEWS_TABLE_NAME = '_views';

	const TYPE_SOURCE = 'source';
	const TYPE_VIEW = 'view';
	const TYPE_SOURCES = 'sources';
	const TYPE_VIEWS = 'views';
	const TYPE_MATERLIALIZED = 'materialized';

	public static string $sourceType;

	public Endpoint $endpointBundle;

	public static QueueProcess $processor;
	public string $originQuery = '';

	public Model $model;

	/**
	 * @param Request $request
	 * @return static
	 * @throws GenericError
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();

		$self->endpointBundle = $request->endpointBundle;

		if ($self->endpointBundle === Endpoint::Sql) {
			$self->originQuery = $request->payload;
			$self->model = SqlModelsHandler::handle(static::$sqlQueryParser::getParsedPayload());
		}

		return $self;
	}

	/** @codingStandardsIgnoreStart */
	public static function hasMatch(
		Request $request
		/** @codingStandardsIgnoreEnd */
	): bool {
		return SqlModelsHandler::handle(Payload::$sqlQueryParser::getParsedPayload()) !== null;
	}

	/**
	 * @return string
	 * @throws Exception
	 */
	public function getHandlerClassName(): string {
		return __NAMESPACE__ . '\\' . $this->model->getHandlerClass();
	}


	public static function getProcessors(): array {
		static::$processor = new QueueProcess();
		return [static::$processor];
	}
}

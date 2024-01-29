<?php
declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Exception;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 */
final class Payload extends BasePayload
{
    const TYPE_SOURCE = 'source';
    const TYPE_VIEW = 'view';

    public static string $type = self::TYPE_VIEW;

    public Endpoint $endpointBundle;

    /**
     * @param  Request  $request
     * @return static
     */
    public static function fromRequest(Request $request): static
    {
        $self = new static();

        $self->endpointBundle = $request->endpointBundle;

        preg_match(self::getPregPattern(), $request->payload, $matches);
        if ($matches[1] === self::TYPE_SOURCE){
            $self::$type = self::TYPE_SOURCE;
        }


        return $self;
    }

    /**
     * @param  Request  $request
     * @return bool
     */
    public static function hasMatch(Request $request): bool
    {

        // CREATE SOURCE/ MATERIALIZED VIEW
        // SHOW SOURCES/VIEWS
        // DROP SOURCE/VIEW
        // ALTER SOURCE/VIEW

        // create source (id, title, body, json, filterA, filterB) type='kafka'

        if (preg_match(self::getPregPattern(), $request->payload) !== false) {
            return true;
        }
        return false;
    }

    public static function getPregPattern(): string
    {
        return '/^(create|show|drop|alter|)\s+(source|materialized\s+view)/usi';
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getHandlerClassName(): string
    {
        return __NAMESPACE__.'\\'.match (static::$type) {
                self::TYPE_SOURCE => 'SourceHandler',
                self::TYPE_VIEW => 'ViewHandler',
                default => throw new Exception('Cannot find handler for request type: '.static::$type),
            };
    }

}

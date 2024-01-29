<?php

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Sources;

enum SourceType{
    case Kafka;
    case MySQL;
}

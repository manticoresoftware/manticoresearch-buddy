<?php

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

class Source
{
    public const TYPE_KAFKA = 'kafka';
    private string $type = self::TYPE_KAFKA;

    public function __construct()
    {
    }

    public function parseQuery(string $query)
    {

        /**
         * create source {source_name}
         * type='kafka'
         * broker='kafka1:9092'
         * topic='myTopic'
         * group='manticore'
         */

        $pattern = "/^create\s+source\s+`?(?<source_name>\w+)`?".
            "\s+type\s?=\s?'(?<type>\w+)'".
            "\s+broker\s?=\s?'(?<broker>[^']*)'".
            "\s+topic\s?=\s?'(?<topic>[^']*)'".
            "(?:\s+group='(?<group>\w+)')?/usi";

        preg_match($pattern, $query, $matches);

        $sourceName = $matches['source_name'];
        $type = $matches['type'];
        $broker = $matches['broker'];
        $topic = $matches['topic'];
        $group = isset($matches['group']) ? $matches['group'] : null;

        echo "Source Name: $sourceName\n";
        echo "Type: $type\n";
        echo "Broker: $broker\n";
        echo "Topic: $topic\n";
        echo "Group: $group\n";
    }
}

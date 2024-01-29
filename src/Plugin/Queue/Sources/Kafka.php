<?php

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Sources;

class Kafka implements SourceInterface
{
    private SourceType $type = SourceType::Kafka;

    /**
     * @var array<string, string> $params
     */
    private array $params;
    private string $name;

    public function __construct(string $query)
    {
        $this->parseQuery($query);
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

        $this->name = $matches['source_name'];
        $this->type = SourceType::Kafka;
        $this->params = [
            'broker'=>$matches['broker'],
            'topic'=>$matches['topic'],
            'group'=>isset($matches['group']) ? $matches['group'] : 'manticore_search_consumer'
        ];


    }

    public function createSourceRecord()
    {
return "INSERT INTO `_sources` (id, name, type, params) VALUES (0,'".$this->name."','".$this->type->name."', '".json_encode($this->params)."')";
    }
}

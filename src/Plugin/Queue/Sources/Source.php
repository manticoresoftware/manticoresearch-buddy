<?php

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Sources;

class Source
{
    const SOURCE_TABLE_NAME = '_source';
    private $source;

    private $type;

    public function __construct(string $query)
    {
        $this->source = $this->getSourceInstance($query);
    }


    public function getSourceInstance(string $query)
    {
        $pattern = "/^create\s+source\s+.*?\s+type\s?=\s?'(?<type>\w+)'/usi";

        preg_match($pattern, $query, $matches);

        $this->type = $matches['type'];
        return match ($matches['type']) {
            SourceType::Kafka->name => new Kafka($query)
        };
    }

    public function create(): string
    {
        return $this->source->createSourceRecord();
    }

    public function view()
    {
        return "SELECT * FROM ".self::SOURCE_TABLE_NAME." WHERE type = '".$this->type."'";
    }

}

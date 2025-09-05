<?php

namespace JiraRestApi\Issue;

class Component implements \JsonSerializable
{
    public $id;

    public function __construct(public $name = null) {}

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return array_filter(get_object_vars($this));
    }
}

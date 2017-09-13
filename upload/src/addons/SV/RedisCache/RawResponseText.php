<?php


namespace SV\RedisCache;

use XF\Http\ResponseStream;

class RawResponseText extends ResponseStream
{
    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct($resource, $length = null)
    {
        if (!is_string($resource))
        {
            throw new \InvalidArgumentException("Must pass valid string in");
        }
        $this->resource = null;
        $this->contents = $resource;
        $this->length = $length;
    }
}

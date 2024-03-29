<?php


namespace SV\RedisCache;

use InvalidArgumentException;
use XF\Http\ResponseStream;
use function is_string;

class RawResponseText extends ResponseStream
{
    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct($resource, $length = null)
    {
        if (!is_string($resource))
        {
            throw new InvalidArgumentException('Must pass valid string in');
        }
        $this->resource = null;
        $this->contents = $resource;
        $this->length = $length;
    }
}

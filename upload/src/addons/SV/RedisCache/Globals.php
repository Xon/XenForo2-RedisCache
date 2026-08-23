<?php

namespace SV\RedisCache;

use function floatval;
use function is_float;
use function is_int;
use function is_numeric;
use function strval;

abstract class Globals
{
    public const PREFIX_KEY = '';

    public const MAX_LIFETIME            = 2592000; /* Redis backend limit */
    public const COMPRESS_PREFIX         = ":\x1f\x8b";
    public const DEFAULT_CONNECT_TIMEOUT = 2.5;
    public const DEFAULT_CONNECT_RETRIES = 1;

    /**
     * @param string|int|float|null $value
     * @param int|float|null $default
     * @return int|float|null
     */
    public static function stringToFloatInt($value, $default = 0)//: int|float
    {
        if (is_int($value) || is_float($value))
        {
            return $value;
        }

        if (!is_numeric($value))
        {
            return $default;
        }

        try
        {
            /** @noinspection PhpWrongStringConcatenationInspection */
            return strval(floatval($value)) + 0;
        }
        catch (\Throwable $e)
        {
            return $default;
        }
    }

    private function __construct() { }
}
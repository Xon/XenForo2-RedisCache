<?php

namespace SV\RedisCache;

abstract class Globals
{
    public const PREFIX_KEY = '';

    public const MAX_LIFETIME            = 2592000; /* Redis backend limit */
    public const COMPRESS_PREFIX         = ":\x1f\x8b";
    public const DEFAULT_CONNECT_TIMEOUT = 2.5;
    public const DEFAULT_CONNECT_RETRIES = 1;

    private function __construct() { }
}
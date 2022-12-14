<?php

namespace SV\RedisCache;


// This class is used to encapsulate global state between layers without using $GLOBAL[] or
// relying on the consumer being loaded correctly by the dynamic class autoloader
class Globals
{
    private function __construct() { }
}
<?php

namespace SV\RedisCache;

use SV\RedisCache\DoctrineCache\Redis as RedisDoctrine;
use SV\RedisCache\SymfonyCache\Redis as RedisSymfony;
use function class_alias;

if (\XF::$versionId < 2030000)
{
    class_alias(RedisDoctrine::class, 'SV\RedisCache\Redis');
}
else
{
    class_alias(RedisSymfony::class, 'SV\RedisCache\Redis');
}

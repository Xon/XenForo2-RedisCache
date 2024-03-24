<?php
/**
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */

namespace SV\RedisCache;

use function class_alias;
if (\XF::$versionId < 2030000)
{
    class_alias(\SV\RedisCache\DoctrineCache\Redis::class, \SV\RedisCache\Redis::class);
}
else
{
    class_alias(\SV\RedisCache\SymfonyCache\Redis::class, \SV\RedisCache\Redis::class);
}

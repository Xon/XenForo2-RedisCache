<?php

namespace SV\RedisCache;

use SV\RedisCache\DoctrineCache\Redis as RedisDoctrine;
use function class_alias;

class_alias(RedisDoctrine::class, 'SV\RedisCache\Redis');

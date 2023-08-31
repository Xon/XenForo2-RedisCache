<?php

namespace SV\RedisCache;

use XF\Container;

class ContainerExtractor extends Container
{
    public static function getFactoryObjects(Container $container): array
    {
        return $container->factoryObjects;
    }
}
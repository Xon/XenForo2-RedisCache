<?php

namespace SV\RedisCache;

class ContainerExtractor extends \XF\Container
{
    public static function getFactoryObjects(\XF\Container $container): array
    {
        return $container->factoryObjects;
    }
}
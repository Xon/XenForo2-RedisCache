<?php

namespace SV\RedisCache;

class Listener
{
    public static function appSetup(\XF\App $app)
    {
        $config = $app->config() ?? [];
        if (!($config['cache']['enabled'] ?? false))
        {
            return;
        }
        $globalNamespace = $config['cache']['namespace'] ?? '';

        /** @var \XF\Container $container */
        $container = $app->container();
        $factoryObjects = ContainerExtractor::getFactoryObjects($container);

        $hasChanges = self::patchConfigBlock($factoryObjects, $globalNamespace, $config['cache'], '');
        foreach ($config['cache']['context'] as $context => &$contextConfig)
        {
            if (self::patchConfigBlock($factoryObjects, $globalNamespace, $contextConfig, $context))
            {
                $hasChanges = true;
            }
        }

        if (!$hasChanges)
        {
            return;
        }

        $container->set('config', $config);
        // note; can't use ContainerExtractor::setFactoryObjects style trick as the context is wrong and uses __set instead of touching the variable directly
        $setter = function ($value) {
            /** @noinspection PhpUndefinedFieldInspection */
            $this->factoryObjects = $value;
        };
        $setterFn = \Closure::bind($setter, $container, $container);
        $setterFn($factoryObjects);
    }

    protected static function patchConfigBlock(array &$factoryObjects, string $globalNamespace, array &$config, $context): bool
    {
        $hasChanges = false;

        if (\strcasecmp($config['provider'] ?? '', 'redis') === 0)
        {
            $config['provider'] = 'SV\RedisCache\Redis';
            $hasChanges = true;
        }

        $obj = $factoryObjects['cache'][$context] ?? null;
        if ($obj instanceof \XF\Cache\RedisCache)
        {
            $cacheObj = new Redis([
                'redis'         => $obj->getRedis(),
                'compress_data' => 0, // for compatibility; do not use compression
                'serializer'    => 'igbinary', // \XF\Cache\RedisCache tries to use igbinary and then php serialization
            ]);
            $cacheObj->setNamespace($config['config']['namespace'] ?? $globalNamespace);
            $factoryObjects['cache'][$context] = $cacheObj;
            $hasChanges = true;
        }

        return $hasChanges;
    }
}
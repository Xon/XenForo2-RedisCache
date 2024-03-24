<?php

namespace SV\RedisCache;

use Doctrine\Common\Cache\CacheProvider;
use XF\App;
use XF\Container;
use function strcasecmp;

abstract class Listener
{
    public static function appSetup(App $app)
    {
        $config = $app->config() ?? [];
        if (!($config['cache']['enabled'] ?? false))
        {
            return;
        }
        $globalNamespace = $config['cache']['namespace'] ?? '';

        /** @var Container $container */
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
            $this->factoryObjects = $value;
        };
        $setterFn = \Closure::bind($setter, $container, $container);
        $setterFn($factoryObjects);
    }

    /**
     * @noinspection PhpUndefinedClassInspection
     * @noinspection PhpFullyQualifiedNameUsageInspection
     * @noinspection PhpUndefinedNamespaceInspection
     */
    protected static function patchConfigBlock(array &$factoryObjects, string $globalNamespace, array &$config, $context): bool
    {
        $hasChanges = false;

        if (strcasecmp($config['provider'] ?? '', 'redis') === 0)
        {
            $config['provider'] = Redis::class;
            $hasChanges = true;
        }

        $obj = $factoryObjects['cache'][$context] ?? null;
        $doPatch = false;
        if (\XF::$versionId < 2030000)
        {
            $doPatch = $obj instanceof \XF\Cache\RedisCache;
        }
        else
        {
            if ($obj instanceof \Symfony\Component\Cache\Adapter\RedisAdapter)
            {
                $doPatch = true;
            }
            else if ($obj instanceof CacheProvider)
            {
                $obj = $obj->getAdapter();
                $doPatch = $obj instanceof \Symfony\Component\Cache\Adapter\RedisAdapter;
            }
        }

        if ($doPatch)
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
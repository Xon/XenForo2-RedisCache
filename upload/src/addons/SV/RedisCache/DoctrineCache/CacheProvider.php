<?php
/**
 * @noinspection PhpMissingParentCallCommonInspection
 * @noinspection PhpMissingParamTypeInspection
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\DoctrineCache;

use function array_combine;
use function array_key_exists;
use function array_map;
use function sprintf;

/**
 * Workaround for Doctrine namespace mangling making working with Redis harder ([ and ] are glob characters, require escaping on the commandline, and are just anoying to deal with.
 * The versioning isn't required, as this is used to implement "deleteAll" on cache providers which do not have a "deleteAll" command. Which XF2 doesn't use
 */
abstract class CacheProvider extends \Doctrine\Common\Cache\CacheProvider
{
    /**
     * The namespace to prefix all cache ids with.
     *
     * @var string
     */
    private $namespace = '';

    /**
     * Sets the namespace to prefix all cache ids with.
     *
     * @param string $namespace
     * @return void
     */
    public function setNamespace($namespace)
    {
        $this->namespace = (string)$namespace;
    }

    /**
     * Retrieves the namespace that prefixes all cache ids.
     *
     * @return string
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    public function fetch($id)
    {
        return $this->doFetch($this->getNamespacedId($id));
    }

    public function fetchMultiple(array $keys): array
    {
        if (empty($keys))
        {
            return [];
        }

        // note: the array_combine() is in place to keep an association between our $keys and the $namespacedKeys
        $namespacedKeys = array_combine($keys, array_map([$this, 'getNamespacedId'], $keys));
        $items = $this->doFetchMultiple($namespacedKeys);
        $foundItems = [];

        // no internal array function supports this sort of mapping: needs to be iterative
        // this filters and combines keys in one pass
        foreach ($namespacedKeys as $requestedKey => $namespacedKey)
        {
            if (array_key_exists($namespacedKey, $items))
            {
                $foundItems[$requestedKey] = $items[$namespacedKey];
            }
        }

        return $foundItems;
    }

    public function contains($id): bool
    {
        return $this->doContains($this->getNamespacedId($id));
    }

    public function store(string $id, $data, int $lifeTime = 0): bool
    {
        return $this->save($id, $data, $lifeTime);
    }

    public function save($id, $data, $lifeTime = 0): bool
    {
        return $this->doSave($this->getNamespacedId($id), $data, $lifeTime);
    }

    public function saveMultiple(array $keysAndValues, $lifetime = 0): bool
    {
        $namespacedKeysAndValues = [];
        foreach ($keysAndValues as $key => $value)
        {
            $namespacedKeysAndValues[$this->getNamespacedId($key)] = $value;
        }

        return $this->doSaveMultiple($namespacedKeysAndValues, $lifetime);
    }

    public function delete($id)
    {
        return $this->doDelete($this->getNamespacedId($id));
    }

    public function deleteAll()
    {
        // remove namespace versioning, and just use flush for delete
        return $this->flushAll();
    }

    /**
     * Prefixes the passed id with the configured namespace value.
     *
     * @param string $id The id to namespace.
     * @return string The namespaced id.
     */
    public function getNamespacedId($id)
    {
        // remove namespace versioning

        return sprintf('%s_%s', $this->namespace, $id);
    }
}

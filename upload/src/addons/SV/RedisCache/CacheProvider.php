<?php


namespace SV\RedisCache;

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
     *
     * @return void
     */
    public function setNamespace($namespace)
    {
        $this->namespace        = (string) $namespace;
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

    /**
     * {@inheritDoc}
     */
    public function deleteAll()
    {
        // remove namespace versioning, and just use flush for delete
        return $this->flushAll();
    }

    /**
     * Prefixes the passed id with the configured namespace value.
     *
     * @param string $id The id to namespace.
     *
     * @return string The namespaced id.
     */
    public function getNamespacedId($id)
    {
        // remove namespace versioning

        return sprintf('%s_%s', $this->namespace, $id);
    }
}

<?php
/**
 * @noinspection PhpMissingParentCallCommonInspection
 * @noinspection PhpMissingParentConstructorInspection
 */

namespace SV\RedisCache\SymfonyCache;

class DeferredCacheItem extends CacheItem
{
    /** @var null|callable():CacheItem */
    public $resolver;

    /**
     * @param string   $key
     * @param callable():CacheItem $resolver
     */
    public function __construct(string $key, callable $resolver)
    {
        $this->key = $key;
        $this->resolver = $resolver;
        $this->value = null;
    }

    public function resolve(): void
    {
        if ($this->resolver === null)
        {
            return;
        }

        $resolver = $this->resolver;
        $this->resolver = null;

        $cacheItem = $resolver();
        $this->isHit = $cacheItem->isHit();
        if ($this->isHit)
        {
            $this->value = $cacheItem->get();
        }
    }

    public function get()
    {
        if ($this->resolver !== null)
        {
            $this->resolve();
        }

        return $this->value;
    }

    public function isHit(): bool
    {
        if ($this->resolver !== null)
        {
            $this->resolve();
        }

        return (bool)$this->isHit;
    }

    public function set($value): CacheItem
    {
        $this->resolver = null;
        $this->value = $value;

        return $this;
    }

    public function expiresAfter($time): CacheItem
    {
        if ($this->resolver !== null)
        {
            $this->resolve();
        }

        return parent::expiresAfter($time);
    }
}
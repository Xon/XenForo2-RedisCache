<?php

namespace SV\RedisCache\SymfonyCache;

use Psr\Cache\CacheItemInterface;
use function min;

class CacheItem implements CacheItemInterface
{
    /** @var string string */
    public $key;
    /** @var mixed */
    public $value;
    /** @var bool|null */
    public $isHit = null;
    /** @var int|null */
    public $expiry = 0;

    public function __construct(string $key, bool $isHit, $value)
    {
        $this->key = $key;
        $this->isHit = $isHit;
        $this->value = $value;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get()
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return (bool)$this->isHit;
    }

    public function set($value): self
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt($expiration): self
    {
        return $this->expiresAtWithType($expiration);
    }

    protected function expiresAtWithType(?\DateTimeInterface $expiration): self
    {
        return $this->expiresAfter($expiration !== null ? (int)min(1, \XF::$time - (int)$expiration->format('U')) : null);
    }

    /**
     * @param \DateInterval|int|null $time
     * @return $this
     */
    public function expiresAfter($time): self
    {
        if ($time instanceof \DateInterval)
        {
            $this->expiry = (int)\DateTime::createFromFormat('U', 0)->add($time)->format('U');
        }
        else
        {
            $this->expiry = (int)$time;
        }

        return $this;
    }
}
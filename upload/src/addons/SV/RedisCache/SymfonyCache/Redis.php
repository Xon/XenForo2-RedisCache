<?php

namespace SV\RedisCache\SymfonyCache;

use CredisException;
use LogicException;
use Psr\Cache\CacheItemInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use SV\RedisCache\Globals;
use SV\RedisCache\Traits\CacheTiming;
use SV\RedisCache\Traits\Cm_Cache_Backend_Redis;
use SV\RedisCache\Traits\ReplicaSelect;
use Symfony\Component\Cache\CacheItem as SymfonyCacheItem;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use XF\CacheFactory;
use function array_combine;
use function array_map;
use function count;
use function get_class;
use function igbinary_serialize;
use function igbinary_unserialize;
use function is_array;
use function is_callable;
use function min;
use function serialize;
use function sprintf;
use function strlen;
use function strtolower;
use function unserialize;

class Redis implements AdapterInterface, CacheInterface, LoggerAwareInterface, ResettableInterface
{
    use Cm_Cache_Backend_Redis {
        _encodeData as protected _encodeDataTrait;
        _decodeData as protected _decodeDataTrait;
    }
    use CacheTiming;
    use ReplicaSelect;

    protected $deferGet = true;
    protected $useIgbinary = false;
    protected $namespace = '';

    public function __construct(array $options = [])
    {
        /** @var CacheFactory $factory */
        $factory = \XF::app()->container('cache.factory');
        $this->namespace = $factory->getNamespace();
        // common options
        $igbinaryPresent = is_callable('igbinary_serialize') && is_callable('igbinary_unserialize');
        $this->useIgbinary = $igbinaryPresent && (empty($options['serializer']) || strtolower($options['serializer']) === 'igbinary');

        // setup various traits
        $this->setupTimers(\XF::$debugMode);
        $this->replicaOptions($options);
        $this->init($options);

        $redisConnector = $options['redis'] ?? null;
        if ($redisConnector instanceof \Redis)
        {
            $this->_redis->setRedisConnector($redisConnector, true);
        }
    }

    public function setNamespace(string $namespace): void
    {
        $this->namespace = $namespace;
    }

    public function getNamespacedId(string $id): string
    {
        // match Doctrine Cache key format
        return sprintf('%s_%s', $this->namespace, $id);
    }

    protected function _encodeData($data, $level)
    {
        $timerForStat = $this->timerForStat;

        $encodedData = $timerForStat('time_encoding', function () use ($data) {
            // XF stores binary data as strings which causes issues using json for serialization
            return $this->useIgbinary ? @igbinary_serialize($data) : @serialize($data);
        });
        unset($data);

        return $timerForStat('time_compression', function () use ($encodedData, $level) {
            return $this->_encodeDataTrait($encodedData, $level);
        });
    }

    protected function _decodeData($data)
    {
        $timerForStat = $this->timerForStat;

        $decompressedData = $timerForStat('time_decompression', function () use ($data) {
            return $this->_decodeDataTrait($data);
        });
        unset($data);
        if ($decompressedData === false)
        {
            return false;
        }
        return $timerForStat('time_decoding', function () use ($decompressedData) {
            return $this->useIgbinary ? @igbinary_unserialize($decompressedData) : @unserialize($decompressedData);
        });
    }

    public function fetch(string $key)
    {
        $result = $this->getItem($key);
        return $result->isHit() ? $result->get() : false;
    }

    public function getItem($key)
    {
        $key = $this->getNamespacedId($key);

        $getter = function () use ($key): CacheItem {
            $redisQueryForStat = $this->redisQueryForStat;
            return $redisQueryForStat('gets', function () use ($key) {
                if ($this->_replica !== null)
                {
                    $data = $this->_replica->get($key);

                    // Prevent compounded effect of cache flood on asynchronously replicating master/slave setup
                    if ($this->_retryReadsOnPrimary && $data === false)
                    {
                        $data = $this->_redis->get($key);
                    }
                }
                else
                {
                    $data = $this->_redis->get($key);
                }

                if ($data === null || $data === false)
                {
                    return new CacheItem($key, false, null);
                }

                $this->stats['bytes_received'] += strlen($data);
                $decoded = $this->_decodeData($data);

                if ($this->_autoExpireLifetime === 0 || !$this->_autoExpireRefreshOnLoad)
                {
                    return new CacheItem($key, true, $decoded);
                }

                $this->_applyAutoExpire($key);

                return new CacheItem($key, true, $decoded);
            });
        };

        if ($this->deferGet)
        {
            return new DeferredCacheItem($key, $getter);
        }

        return $getter();
    }

    public function fetchMultiple(array $keys): array
    {
        $results = [];

        $values = $this->getItems($keys);
        foreach($values as $key => $value)
        {
            if ($value->isHit())
            {
                $results[$key] = $value->get();
            }
        }

        return $results;
    }

    public function store(string $id, $data, int $lifeTime = 0): bool
    {
        $key = $this->getNamespacedId($id);

        return $this->saveInternal($key, $data, $lifeTime);
    }

    public function getItems(array $keys = [])
    {
        if (count($keys) === 0)
        {
            return [];
        }

        $ids = array_map([$this, 'getNamespacedId'], $keys);
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('gets', function () use ($keys, $ids) {
            $redis = $this->_replica ?? $this->_redis;

            $fetchedItems = $redis->mGet($ids);
            if (!is_array($fetchedItems))
            {
                throw new CredisException('Redis::mget returned an unexpected valid, the redis server is likely in a non-operational state');
            }

            $autoExpire = $this->_autoExpireLifetime === 0 || !$this->_autoExpireRefreshOnLoad;
            $decoded = [];
            $mgetResults = array_combine($keys, $fetchedItems);
            foreach ($mgetResults as $key => $data)
            {
                if ($data === null || $data === false)
                {
                    $decoded[$key] = new CacheItem($key, false, null);
                    continue;
                }

                $this->stats['bytes_received'] += strlen($data);
                $decodedData = $this->_decodeData($data);
                if ($decodedData === false)
                {
                    $decoded[$key] = new CacheItem($key, false, null);
                    continue;
                }
                $decoded[$key] = new CacheItem($key, true, $decodedData);

                if ($autoExpire)
                {
                    $this->_applyAutoExpire($key);
                }
            }

            return $decoded;
        });
    }

    public function hasItem($key)
    {
        $key = $this->getNamespacedId($key);
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('gets', function () use ($key) {
            // Don't use replica for this since `doContains`/`test` is usually used for locking
            return $this->_redis->exists($key);
        });
    }

    protected function saveInternal(string $key, $value, int $expiry)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('sets', function () use ($key, $value, $expiry) {
            $data = $this->_encodeData($value, $this->_compressData);
            $lifetime = $this->_getAutoExpiringLifetime($expiry, $key);
            $lifeTime = min($lifetime, Globals::MAX_LIFETIME);

            $this->stats['bytes_sent'] += strlen($data);

            if ($lifeTime > 0)
            {
                $response = $this->_redis->set($key, $data, $lifeTime);
            }
            else
            {
                $response = $this->_redis->set($key, $data);
            }

            return $response === true;
        });
    }

    protected function decomposeSymfonyCacheItem(SymfonyCacheItem $item): array
    {
        // A consequence of Symfony/Cache's + PSR-6 design
        static $fetcher = null;
        if ($fetcher == null)
        {
            $fetcher = \Closure::bind(
                static function (SymfonyCacheItem $item) {
                    return [$item->key, $item->value, $item->expiry, $item->isHit];
                },
                null,
                SymfonyCacheItem::class
            );
        }

        return $fetcher($item);
    }

    public function save(CacheItemInterface $item)
    {
        // PSR-6's CacheItemPoolInterface::save is broken.
        // The implementation needs to bind to the actual instance generated by 'get', which means every save must cause a matching get beforehand
        // Worse, the expiry must be explicitly set every time the load/save cycle occurs
        if ($item instanceof SymfonyCacheItem)
        {
            [$key, $value, $expiry, $isHit] = $this->decomposeSymfonyCacheItem($item);
            if ($expiry !== null)
            {
                $expiry = min(0, \XF::$time - (int)$expiry);
            }
        }
        else if ($item instanceof CacheItem)
        {
            if ($item instanceof DeferredCacheItem)
            {
                if ($item->resolver !== null)
                {
                    throw new LogicException('Require an explicit value to be set');
                }
            }
            $key = $item->key;
            $value = $item->value;
            $expiry = $item->expiry;
            $isHit = $item->isHit;
        }
        else
        {
            throw new LogicException('Unknown CacheItemInterface subclass'.get_class($item));
        }

        if (\XF::$developmentMode && $isHit && $expiry === null)
        {
            // no explicit expiry set. probably an error
            throw new LogicException('Require an explicit expiry to be set');
        }

        return $this->saveInternal($key, $value, (int)$expiry);
    }

    public function saveDeferred(CacheItemInterface $item)
    {
        // PSR-6's CacheItemPoolInterface::saveDeferred just isn't safe to use
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }


    public function deleteItem($key)
    {
        $key = $this->getNamespacedId($key);
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('deletes', function () use ($key) {
            return $this->_redis->del($key) >= 0;
        });
    }

    public function deleteItems(array $keys)
    {
        $ids = array_map([$this, 'getNamespacedId'], $keys);
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('deletes', function () use ($ids) {
            $this->_redis->pipeline();
            foreach ($ids as $id)
            {
                $this->_redis->del($id);
            }
            $this->_redis->exec();

            return true;
        });
    }

    public function clear(string $prefix = '')
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('flushes', function () {
            /** @var string|bool $response */
            $response = $this->_redis->flushDb();

            return $response === true || $response === 'OK';
        });
    }

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null)
    {
        $item = $this->getItem($key);
        $metadata = $item->getMetadata();

        return $item->get();
    }

    public function delete(string $key): bool
    {
        return $this->deleteItem($key);
    }

    public function reset(): void
    {
        $this->clear();
    }

    public function setLogger(LoggerInterface $logger)
    {
        if (\XF::$developmentMode)
        {
            throw new LogicException('Not supported');
        }
    }
}
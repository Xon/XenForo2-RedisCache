<?php

namespace SV\RedisCache\DoctrineCache;

/**
 * Redis adapter for XenForo2 & Doctrine
 */

use CredisException;
use Doctrine\Common\Cache\Cache;
use SV\RedisCache\Globals;
use SV\RedisCache\Traits\CacheTiming;
use SV\RedisCache\Traits\Cm_Cache_Backend_Redis;
use SV\RedisCache\Traits\ReplicaSelect;
use function array_combine;
use function count;
use function igbinary_serialize;
use function igbinary_unserialize;
use function is_array;
use function is_callable;
use function min;
use function serialize;
use function strlen;
use function strtolower;
use function unserialize;

class Redis extends CacheProvider
{
    use Cm_Cache_Backend_Redis {
        _encodeData as protected _encodeDataTrait;
        _decodeData as protected _decodeDataTrait;
    }
    use CacheTiming;
    use ReplicaSelect;

    protected $useIgbinary = false;

    public function __construct(array $options = [])
    {
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

    /** @noinspection PhpMissingParentCallCommonInspection */
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

    /** @noinspection PhpMissingParentCallCommonInspection */
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

    protected function doFetch($id)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('gets', function () use ($id) {
            if ($this->_replica !== null)
            {
                $data = $this->_replica->get($id);

                // Prevent compounded effect of cache flood on asynchronously replicating primary/replica setup
                if ($this->_retryReadsOnPrimary && $data === false)
                {
                    $data = $this->_redis->get($id);
                }
            }
            else
            {
                $data = $this->_redis->get($id);
            }

            if ($data === null || $data === false)
            {
                return false;
            }

            $this->stats['bytes_received'] += strlen($data);
            $decoded = $this->_decodeData($data);

            if ($this->_autoExpireLifetime === 0 || !$this->_autoExpireRefreshOnLoad)
            {
                return $decoded;
            }

            $this->_applyAutoExpire($id);

            return $decoded;
        });
    }

    /** @noinspection PhpMissingParentCallCommonInspection */
    protected function doFetchMultiple(array $keys)
    {
        if (count($keys) === 0)
        {
            return [];
        }

        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('gets', function () use ($keys) {
            $redis = $this->_replica ?? $this->_redis;

            $fetchedItems = $redis->mGet($keys);
            if (!is_array($fetchedItems))
            {
                throw new CredisException('Redis::mGet returned an unexpected valid, the redis server is likely in a non-operational state');
            }

            $autoExpire = $this->_autoExpireLifetime === 0 || !$this->_autoExpireRefreshOnLoad;
            $decoded = [];
            $mgetResults = array_combine($keys, $fetchedItems);
            foreach ($mgetResults as $key => $data)
            {
                if ($data === null || $data === false)
                {
                    continue;
                }

                $this->stats['bytes_received'] += strlen($data);
                $decodedData = $this->_decodeData($data);
                if ($decodedData === false)
                {
                    continue;
                }
                $decoded[$key] = $decodedData;

                if ($autoExpire)
                {
                    $this->_applyAutoExpire($key);
                }
            }

            return $decoded;
        });
    }

    protected function doContains($id)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('gets', function () use ($id) {
            // Don't use replica for this since `doContains`/`test` is usually used for locking
            return $this->_redis->exists($id);
        });
    }

    /** @noinspection PhpMissingParentCallCommonInspection */
    protected function doSaveMultiple(array $keysAndValues, $lifetime = 0)
    {
        foreach ($keysAndValues as $key => $value)
        {
            $this->doSave($key, $value, $lifetime);
        }
    }

    protected function doSave($id, $data, $lifeTime = 0)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('sets', function () use ($id, $data, $lifeTime) {
            $data = $this->_encodeData($data, $this->_compressData);
            $lifeTime = (int)$lifeTime;
            $lifetime = $this->_getAutoExpiringLifetime($lifeTime, $id);
            $lifeTime = min($lifetime, Globals::MAX_LIFETIME);

            $this->stats['bytes_sent'] += strlen($data);

            if ($lifeTime > 0)
            {
                $response = $this->_redis->set($id, $data, $lifeTime);
            }
            else
            {
                $response = $this->_redis->set($id, $data);
            }

            return $response === true;
        });
    }

    protected function doDelete($id)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('deletes', function () use ($id) {
            return $this->_redis->del($id) >= 0;
        });
    }

    protected function doFlush()
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('flushes', function () {
            /** @var string|bool $response */
            $response = $this->_redis->flushDb();

            return $response === true || $response === 'OK';
        });
    }

    protected function doGetStats()
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('gets', function () {
            //$redis = $this->_replica ?? $this->_redis;
            $info = $this->_redis->info();

            return [
                Cache::STATS_HITS             => $info['Stats']['keyspace_hits'],
                Cache::STATS_MISSES           => $info['Stats']['keyspace_misses'],
                Cache::STATS_UPTIME           => $info['Server']['uptime_in_seconds'],
                Cache::STATS_MEMORY_USAGE     => $info['Memory']['used_memory'],
                Cache::STATS_MEMORY_AVAILABLE => false,
            ];
        });
    }
}

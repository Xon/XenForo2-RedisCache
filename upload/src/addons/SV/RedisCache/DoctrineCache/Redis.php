<?php

namespace SV\RedisCache\DoctrineCache;

/**
 * Redis adapter for XenForo2 & Doctrine
 */

use Doctrine\Common\Cache\Cache;
use SV\RedisCache\Cm_Cache_Backend_Redis;

require_once('Credis/Client.php');
require_once('Credis/Sentinel.php');

class Redis extends Cm_Cache_Backend_Redis
{
    protected $useIgbinary = false;

    protected $stats = [
        'gets'               => 0,
        'gets.time'          => 0,
        'sets'               => 0,
        'sets.time'          => 0,
        'deletes'            => 0,
        'deletes.time'       => 0,
        'flushes'            => 0,
        'flushes.time'       => 0,
        'bytes_sent'         => 0,
        'bytes_received'     => 0,
        'time_compression'   => 0,
        'time_decompression' => 0,
        'time_encoding'      => 0,
        'time_decoding'      => 0,
    ];

    /**
     * @var bool
     */
    protected $debug = false;

    /** @var \Closure|null */
    protected $redisQueryForStat = null;
    /** @var \Closure|null */
    protected $timerForStat = null;

    protected function redisQueryForStat($stat, \Closure $callback)
    {
        $this->stats[$stat]++;

        return $callback();
    }

    protected function redisQueryForStatDebug($stat, \Closure $callback)
    {
        $this->stats[$stat]++;
        /** @var float $startTime */
        $startTime = \microtime(true);
        try
        {
            return $callback();
        }
        finally
        {
            /** @var float $endTime */
            $endTime = \microtime(true);

            $this->stats[$stat . '.time'] += ($endTime - $startTime);
        }
    }

    /**
     * @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection
     */
    protected function redisQueryForStatDebugPhp73($stat, \Closure $callback)
    {
        $this->stats[$stat]++;

        /** @var float $startTime */
        $startTime = \hrtime(true);
        try
        {
            return $callback();
        }
        finally
        {
            /** @var float $endTime */
            $endTime = \hrtime(true);

            $this->stats[$stat . '.time'] += ($endTime - $startTime) / 1000000000;
        }
    }

    /** @noinspection PhpUnusedParameterInspection */
    protected function timerForStat($stat, \Closure $callback)
    {
        return $callback();
    }

    protected function timerForStatDebug($stat, \Closure $callback)
    {
        /** @var float $startTime */
        $startTime = \microtime(true);
        try
        {
            return $callback();
        }
        finally
        {
            /** @var float $endTime */
            $endTime = \microtime(true);

            $this->stats[$stat] += ($endTime - $startTime);
        }
    }

    /**
     * @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection
     */
    protected function timerForStatDebugPhp73($stat, \Closure $callback)
    {
        /** @var float $startTime */
        $startTime = \hrtime(true);
        try
        {
            return $callback();
        }
        finally
        {
            /** @var float $endTime */
            $endTime = \hrtime(true);

            $this->stats[$stat] += ($endTime - $startTime) / 1000000000;
        }
    }

    public function __construct(array $options = [])
    {
        $this->debug = \XF::$debugMode;

        if ($this->debug)
        {
            if (\function_exists('\hrtime'))
            {
                $this->timerForStat = [$this, 'timerForStatDebugPhp73'];
                $this->redisQueryForStat = [$this, 'redisQueryForStatDebugPhp73'];
            }
            else
            {
                $this->timerForStat = [$this, 'timerForStatDebug'];
                $this->redisQueryForStat = [$this, 'redisQueryForStatDebug'];
            }
        }
        else
        {
            $this->timerForStat = [$this, 'timerForStat'];
            $this->redisQueryForStat = [$this, 'redisQueryForStat'];
        }
        if (\is_callable('\Closure::fromCallable'))
        {
            /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
            $this->redisQueryForStat = \Closure::fromCallable($this->redisQueryForStat);
            /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
            $this->timerForStat = \Closure::fromCallable($this->timerForStat);
        }

        // normalize old options to newer ones
        $options['sentinel_primary'] = $options['sentinel_primary'] ?? $options['sentinel_master'] ?? $options['sentinel_master_set'] ?? $options['sentinel_primary_set'] ?? null;
        unset($options['sentinel_master'], $options['sentinel_master_set'], $options['sentinel_primary_set']);
        $options['sentinel_primary_verify'] = $options['sentinel_primary_verify'] ?? $options['sentinel_master_verify'] ?? null;
        unset($options['sentinel_master_verify']);
        $options['primary_write_only'] = $options['primary_write_only'] ?? $options['master_write_only'] ?? null;
        unset($options['master_write_only']);
        $options['retry_reads_on_primary'] = $options['retry_reads_on_primary'] ?? $options['retry_reads_on_master'] ?? false;
        unset($options['retry_reads_on_master']);

        $options['load_from_replica'] = $options['load_from_replica'] ?? $options['load_from_slave'] ?? null;
        unset($options['load_from_slave']);
        $options['load_from_replicas'] = $options['load_from_replicas'] ?? $options['load_from_slaves'] ?? null;
        unset($options['load_from_slaves']);
        $options['replica_select_callable'] = $options['replica_select_callable'] ?? $options['replica-select'] ?? $options['slave_select_callable'] ?? $options['slave-select'] ?? 'preferLocalReplica';
        unset($options['replica-select'], $options['slave_select_callable'], $options['slave-select']);

        // if it is a string, assume it is some method on this class
        $replicaSelect = $options['replica_select_callable'] ?? null;
        if (\is_string($replicaSelect))
        {
            $options['replica_select_callable'] = [$this, $replicaSelect];
        }

        $igbinaryPresent = \is_callable('igbinary_serialize') && \is_callable('igbinary_unserialize');
        $this->useIgbinary = $igbinaryPresent && (empty($options['serializer']) || \strtolower($options['serializer']) === 'igbinary');

        // stock phpredis connector compatibility
        if (!empty($options['host']))
        {
            $options['server'] = $options['host'];
        }
        if (empty($options['server']))
        {
            $options['server'] = 'localhost';
        }

        parent::__construct($options);

        $redisConnector = $options['redis'] ?? null;
        if ($redisConnector instanceof \Redis)
        {
            $this->_redis->setRedisConnector($redisConnector, true);
        }
    }

    protected function getLocalIps(array $ips = null): array
    {
        if (!\is_array($ips))
        {
            // I can't believe there isn't a better way
            try
            {
                $output = \shell_exec('hostname --all-ip-addresses');
            }
            catch (\Exception $e)
            {
                $output = '';
            }
            if ($output)
            {
                $ips = \array_fill_keys(\array_filter(\array_map('\trim', \explode(' ', $output))), true);
            }
        }

        return $ips ?: [];
    }

    /**
     * @param array<string,string> $ips
     * @param \Credis_Client[] $replicas
     * @param \Credis_Client   $primary
     * @return \Credis_Client|null
     * @noinspection PhpUnusedParameterInspection
     */
    protected function selectLocalRedis(array $ips, array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        if ($ips)
        {
            foreach ($replicas as $replica)
            {
                // replica host is just an ip
                $host = $replica->getHost();
                if (isset($ips[$host]))
                {
                    return $replica;
                }
            }
        }

        $replicaKey = \array_rand($replicas);

        return $replicas[$replicaKey];
    }

    /**
     * @deprecated
     */
    public function preferLocalSlave(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        return $this->preferLocalReplica($replicas, $primary);
    }

    /**
     * @param \Credis_Client[] $replicas
     * @param  \Credis_Client  $primary
     * @return \Credis_Client|null
     */
    public function preferLocalReplica(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        $ips = $this->getLocalIps();

        return $this->selectLocalRedis($ips, $replicas, $primary);
    }

    /**
     * @deprecated
     */
    public function preferLocalSlaveLocalDisk(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        return $this->preferLocalReplicaLocalDisk($replicas, $primary);
    }

    /**
     * @param \Credis_Client[] $replicas
     * @param \Credis_Client   $primary
     * @return \Credis_Client|null
     */
    protected function preferLocalReplicaLocalDisk(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        $output = @\file_get_contents('/tmp/local_ips');
        if ($output === false)
        {
            try
            {
                $output = \shell_exec('hostname --all-ip-addresses');
            }
            catch (\Exception $e)
            {
                $output = '';
            }
            if ($output !== false)
            {
                \file_put_contents('/tmp/local_ips', $output);
            }
        }

        $ips = null;
        if ($output)
        {
            $ips = \array_fill_keys(\array_filter(\array_map('\trim', \explode(' ', $output))), true);
        }

        return $this->selectLocalRedis($ips ?: [], $replicas, $primary);
    }

    /**
     * @deprecated
     */
    public function preferLocalSlaveAPCu(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        return $this->preferLocalReplicaAPCu($replicas, $primary);
    }

    /**
     * @param \Credis_Client[] $replicas
     * @param \Credis_Client   $primary
     * @return \Credis_Client|null
     */
    public function preferLocalReplicaAPCu(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        $ips = null;
        if (\function_exists('apcu_fetch'))
        {
            $ips = \apcu_fetch('localips', $hasIps);
        }
        if (!\is_array($ips))
        {
            $ips = $this->getLocalIps();
            if (\function_exists('apcu_store'))
            {
                // bit racing on the first connection, but local IPs rarely change.
                \apcu_store('localips', $ips);
            }
        }

        return $this->selectLocalRedis($ips ?: [], $replicas, $primary);
    }

    public function getCompressThreshold(): int
    {
        return $this->_compressThreshold;
    }

    public function setCompressThreshold(int $value)
    {
        $this->_compressThreshold = $value;
    }

    public function DecodeData(string $data): string
    {
        return $this->_decodeData($data);
    }

    public function getCredis(bool $allowReplica = false): \Credis_Client
    {
        if ($allowReplica && $this->_replica !== null)
        {
            return $this->_replica;
        }

        return $this->_redis;
    }

    /**
     * @return ?\Credis_Client
     * @deprecated
     */
    public function getSlaveCredis(): ?\Credis_Client
    {
        return $this->getReplicaCredis();
    }

    /**
     * @deprecated
     * @param \Credis_Client|null $replica
     * @return void
     */
    public function setSlaveCredis(?\Credis_Client $replica): void
    {
        $this->_replica = $replica;
    }

    public function getReplicaCredis(): ?\Credis_Client
    {
        return $this->_replica;
    }

    public function setReplicaCredis(?\Credis_Client $replica): void
    {
        $this->_replica = $replica;
    }

    public function useLua(): bool
    {
        return $this->_useLua;
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

            $this->stats['bytes_received'] += \strlen($data);
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
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('gets', function () use ($keys) {
            $redis = $this->_replica ?? $this->_redis;

            $fetchedItems = $redis->mGet($keys);
            if (!is_array($fetchedItems))
            {
                throw new \CredisException('Redis::mGet returned an unexpected valid, the redis server is likely in a non-operational state');
            }

            $autoExpire = $this->_autoExpireLifetime === 0 || !$this->_autoExpireRefreshOnLoad;
            $decoded = [];
            $mgetResults = \array_combine($keys, $fetchedItems);
            foreach ($mgetResults as $key => $data)
            {
                if ($data === null || $data === false)
                {
                    continue;
                }

                $this->stats['bytes_received'] += \strlen($data);
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
    protected function _encodeData($data, $level)
    {
        $timerForStat = $this->timerForStat;

        $encodedData = $timerForStat('time_encoding', function () use ($data) {
            // XF stores binary data as strings which causes issues using json for serialization
            return $this->useIgbinary ? @\igbinary_serialize($data) : @\serialize($data);
        });
        unset($data);

        return $timerForStat('time_compression', function () use ($encodedData, $level) {
            return parent::_encodeData($encodedData, $level);
        });
    }

    /** @noinspection PhpMissingParentCallCommonInspection */
    protected function _decodeData($data)
    {
        $timerForStat = $this->timerForStat;

        $decompressedData = $timerForStat('time_decompression', function () use ($data) {
            return parent::_decodeData($data);
        });
        unset($data);
        if ($decompressedData === false)
        {
            return false;
        }
        return $timerForStat('time_decoding', function () use ($decompressedData) {
            return $this->useIgbinary ? @\igbinary_unserialize($decompressedData) : @\unserialize($decompressedData);
        });
    }

    /** @noinspection PhpMissingParentCallCommonInspection */
    protected function doSaveMultiple(array $keysAndValues, $lifetime = 0)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('sets', function () use ($keysAndValues, $lifetime) {
            $lifetime = (int)$lifetime;
            foreach ($keysAndValues as &$data)
            {
                $data = $this->_encodeData($data, $this->_compressData);
                $this->stats['bytes_sent'] += \strlen($data);
            }
            unset($data);

            if ($lifetime > 0)
            {
                $this->_redis->multi();
                $this->_redis->mSet($keysAndValues);
                foreach ($keysAndValues as $key => $null)
                {
                    $perKeyLifeTime = $lifetime;
                    $perKeyLifeTime = $this->_getAutoExpiringLifetime($perKeyLifeTime, $key);
                    $perKeyLifeTime = \min($perKeyLifeTime, self::MAX_LIFETIME);
                    $this->_redis->expire($key, $perKeyLifeTime);
                }
                $this->_redis->exec();
            }
            else
            {
                $this->_redis->mSet($keysAndValues);
            }

            return true;
        });
    }

    protected function doSave($id, $data, $lifeTime = 0)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('sets', function () use ($id, $data, $lifeTime) {
            $data = $this->_encodeData($data, $this->_compressData);
            $lifeTime = (int)$lifeTime;
            $lifetime = $this->_getAutoExpiringLifetime($lifeTime, $id);
            $lifeTime = \min($lifetime, self::MAX_LIFETIME);

            $this->stats['bytes_sent'] += \strlen($data);

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

    public function getRedisStats(): array
    {
        return $this->stats;
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

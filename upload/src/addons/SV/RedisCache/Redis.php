<?php

namespace SV\RedisCache;

/**
 * Redis adapter for XenForo2 & Doctrine
 */

use Doctrine\Common\Cache\Cache;

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

    /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
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

    /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
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
        if (is_callable('\Closure::fromCallable'))
        {
            /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
            $this->redisQueryForStat = \Closure::fromCallable($this->redisQueryForStat);
            /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
            $this->timerForStat = \Closure::fromCallable($this->timerForStat);
        }

        if (!isset($options['slave_select_callable']))
        {
            $options['slave_select_callable'] = [$this, 'preferLocalSlave'];
        }
        // if it is a string, assume it is some method on this class
        if (isset($options['slave_select_callable']) && \is_string($options['slave_select_callable']))
        {
            $options['slave_select_callable'] = [$this, $options['slave_select_callable']];
        }

        $igbinaryPresent = is_callable('igbinary_serialize') && \is_callable('igbinary_unserialize');
        $this->useIgbinary = $igbinaryPresent && (empty($options['serializer']) || \utf8_strtolower($options['serializer']) === 'igbinary');

        if (!empty($options['host']))
        {
            $options['server'] = $options['host'];
        }
        if (empty($options['server']))
        {
            $options['server'] = 'localhost';
        }

        parent::__construct($options);
    }

    protected function getLocalIps(array $ips = null): array
    {
        if (!\is_array($ips))
        {
            // I can't believe there isn't a better way
            try
            {
                $output = shell_exec("hostname --all-ip-addresses");
            }
            catch (\Exception $e)
            {
                $output = '';
            }
            if ($output)
            {
                $ips = array_fill_keys(array_filter(array_map('trim', (explode(' ', $output)))), true);
            }
        }

        return $ips ?: [];
    }

    /**
     * @param array            $ips
     * @param \Credis_Client[] $slaves
     * @param                  $master
     * @return \Credis_Client|null
     * @noinspection PhpUnusedParameterInspection
     */
    protected function selectLocalRedis(array $ips, array $slaves, $master)
    {
        if ($ips)
        {
            foreach ($slaves as $slave)
            {
                // slave host is just an ip
                $host = $slave->getHost();
                if (isset($ips[$host]))
                {
                    return $slave;
                }
            }
        }

        $slaveKey = array_rand($slaves);

        return $slaves[$slaveKey];
    }

    /**
     * @param \Credis_Client[] $slaves
     * @param                  $master
     * @return \Credis_Client|null
     */
    public function preferLocalSlave(array $slaves, $master)
    {
        $ips = $this->getLocalIps();

        return $this->selectLocalRedis($ips, $slaves, $master);
    }

    /**
     * @param \Credis_Client[] $slaves
     * @param                  $master
     * @return \Credis_Client|null
     */
    protected function preferLocalSlaveLocalDisk(array $slaves, $master)
    {
        $output = @file_get_contents('/tmp/local_ips');
        if ($output === false)
        {
            try
            {
                $output = shell_exec("hostname --all-ip-addresses");
            }
            catch (\Exception $e)
            {
                $output = '';
            }
            if ($output !== false)
            {
                file_put_contents('/tmp/local_ips', $output);
            }
        }

        $ips = null;
        if ($output)
        {
            $ips = array_fill_keys(array_filter(array_map('trim', (explode(' ', $output)))), true);
        }

        return $this->selectLocalRedis($ips ?: [], $slaves, $master);
    }

    /**
     * @param \Credis_Client[] $slaves
     * @param                  $master
     * @return \Credis_Client|null
     */
    public function preferLocalSlaveAPCu(array $slaves, $master)
    {
        $ips = null;
        if (\function_exists('apcu_fetch'))
        {
            $ips = apcu_fetch('localips', $hasIps);
        }
        if (!\is_array($ips))
        {
            $ips = $this->getLocalIps();
            if (function_exists('apcu_store'))
            {
                // bit racing on the first connection, but local IPs rarely change.
                apcu_store('localips', $ips);
            }
        }

        return $this->selectLocalRedis($ips ?: [], $slaves, $master);
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

    public function getCredis(bool $allowSlave = false): \Credis_Client
    {
        if ($allowSlave && $this->_slave)
        {
            return $this->_slave;
        }

        return $this->_redis;
    }

    public function getSlaveCredis(): \Credis_Client
    {
        return $this->_slave;
    }

    public function setSlaveCredis(\Credis_Client $slave)
    {
        $this->_slave = $slave;
    }

    public function useLua(): bool
    {
        return $this->_useLua;
    }

    /**
     * {@inheritdoc}
     */
    protected function doFetch($id)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('gets', function () use ($id) {
            if ($this->_slave)
            {
                $data = $this->_slave->get($id);

                // Prevent compounded effect of cache flood on asynchronously replicating master/slave setup
                if ($this->_retryReadsOnMaster && $data === false)
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

    /**
     * {@inheritdoc}
     */
    protected function doFetchMultiple(array $keys)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('gets', function () use ($keys) {
            $redis = $this->_slave ?: $this->_redis;

            $fetchedItems = $redis->mget($keys);

            $autoExpire = $this->_autoExpireLifetime === 0 || !$this->_autoExpireRefreshOnLoad;
            $decoded = [];
            $mgetResults = array_combine($keys, $fetchedItems);
            foreach ($mgetResults as $key => $data)
            {
                if ($data === null || $data === false)
                {
                    continue;
                }

                $this->stats['bytes_received'] += \strlen($data);
                $decoded[$key] = $this->_decodeData($data);

                if ($autoExpire)
                {
                    $this->_applyAutoExpire($key);
                }
            }

            return $decoded;
        });
    }

    /**
     * {@inheritdoc}
     */
    protected function doContains($id)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('gets', function () use ($id) {
            // Don't use slave for this since `doContains`/`test` is usually used for locking
            return $this->_redis->exists($id);
        });
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
            return parent::_encodeData($encodedData, $level);
        });
    }

    protected function _decodeData($data)
    {
        $timerForStat = $this->timerForStat;

        $decompressedData = $timerForStat('time_decompression', function () use ($data) {
            return parent::_decodeData($data);
        });
        unset($data);

        return $timerForStat('time_decoding', function () use ($decompressedData) {
            return $this->useIgbinary ? @igbinary_unserialize($decompressedData) : @unserialize($decompressedData);
        });
    }

    /**
     * {@inheritdoc}
     */
    protected function doSave($id, $data, $lifeTime = 0)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('sets', function () use ($id, $data, $lifeTime) {
            $data = $this->_encodeData($data, $this->_compressData);
            $lifetime = $this->_getAutoExpiringLifetime($lifeTime, $id);
            $lifeTime = min($lifetime, self::MAX_LIFETIME);

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

    /**
     * {@inheritdoc}
     */
    protected function doDelete($id)
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('deletes', function () use ($id) {
            return $this->_redis->del($id) >= 0;
        });
    }

    /**
     * {@inheritdoc}
     */
    protected function doFlush()
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('flushes', function () {
            /** @var string|bool $response */
            $response = $this->_redis->flushdb();

            return $response === true || $response === 'OK';
        });
    }

    public function getRedisStats(): array
    {
        return $this->stats;
    }

    /**
     * {@inheritdoc}
     */
    protected function doGetStats()
    {
        $redisQueryForStat = $this->redisQueryForStat;

        return $redisQueryForStat('gets', function () {
            //$redis = $this->_slave ? $this->_slave : $this->_redis;
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

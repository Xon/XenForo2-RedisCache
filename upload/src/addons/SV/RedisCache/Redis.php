<?php

namespace SV\RedisCache;

/**
 * Redis adapter for XenForo2 & Doctrine
 *
 */
 
require_once('Credis/Client.php');
require_once('Credis/Sentinel.php');
class Redis  extends Cm_Cache_Backend_Redis
{
    public function __construct($options = array())
    {
        if (!isset($options['slave_select_callable']))
        {
            $options['slave_select_callable'] = array($this, 'preferLocalSlave');
        }
        // if it is a string, assume it is some method on this class
        if (isset($options['slave_select_callable']) && is_string($options['slave_select_callable']))
        {
            $options['slave_select_callable'] = array($this, $options['slave_select_callable']);
        }
        parent::__construct($options);
    }

    protected function getLocalIps(array $ips = null)
    {
        if (!is_array($ips))
        {
            // I can't believe there isn't a better way
            try
            {
                $output = shell_exec("hostname --all-ip-addresses");
            }
            catch(Exception $e) { $output = ''; }
            if ($output)
            {
                $ips = array_fill_keys(array_filter(array_map('trim', (explode(' ', $output)))), true);
            }
        }
        return $ips;
    }

    protected function selectLocalRedis(array $ips = null, array $slaves, $master)
    {
        if ($ips)
        {
            /* @var $slave Credis_Client */
            foreach($slaves as $slave)
            {
                // slave host is just an ip
                $host = $slave->getHost();
                if (isset($ips[$host]))
                {
                    return $slave;
                }
            }
        }

        $slaveKey = array_rand($slaves, 1);
        return $slaves[$slaveKey];
    }

    public function preferLocalSlave(array $slaves, $master)
    {
        $ips = $this->getLocalIps();
        return $this->selectLocalRedis($ips, $slaves, $master);
    }

    protected function preferLocalSlaveLocalDisk(array $slaves, $master)
    {
        $output = @file_get_contents('/tmp/local_ips');
        if ($output === false)
        {
            try
            {
                $output = shell_exec("hostname --all-ip-addresses");
            }
            catch(Exception $e) { $output = ''; }
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
        return $this->selectLocalRedis($ips, $slaves, $master);
    }

    public function preferLocalSlaveAPCu(array $slaves, $master)
    {
        $ips = null;
        if (function_exists('apcu_fetch'))
        {
            $ips = apcu_fetch('localips', $hasIps);
        }
        if (!is_array($ips))
        {
            $ips = $this->getLocalIps();
            if (function_exists('apcu_store'))
            {
                // bit racing on the first connection, but local IPs rarely change.
                apcu_store('localips', $ips);
            }
        }
        return $this->selectLocalRedis($ips, $slaves, $master);
    }

    public function getCompressThreshold()
    {
        return $this->_compressThreshold;
    }

    public function setCompressThreshold($value)
    {
        $this->_compressThreshold = $value;
    }

    public function DecodeData($data)
    {
        return $this->_decodeData($data);
    }

    public function getCredis($allowSlave = false)
    {
        if ($allowSlave && $this->_slave)
        {
            return $this->_slave;
        }
        return $this->_redis;
    }

    public function getSlaveCredis()
    {
        return $this->_slave;
    }

    public function setSlaveCredis($slave)
    {
        $this->_slave = $slave;
    }

    public function useLua()
    {
        return $this->_useLua;
    }

    /**
     * {@inheritdoc}
     */
    protected function doFetch($id)
    {
        if ($this->_slave) {
            $data = $this->_slave->get(self::PREFIX_KEY.$id);
        } else {
            $data = $this->_redis->get(self::PREFIX_KEY.$id);
        }
        if ($data === NULL) {
            return FALSE;
        }

        $decoded = $this->_decodeData($data);

        if ($this->_autoExpireLifetime === 0 || !$this->_autoExpireRefreshOnLoad) {
            return $decoded;
        }

        $this->_applyAutoExpire($id);


        return $decoded;
    }

    protected function _applyAutoExpire($id)
    {
        $matches = $this->_matchesAutoExpiringPattern($id);
        if ($matches) {
            $this->_redis->expire(self::PREFIX_KEY.$id, min($this->_autoExpireLifetime, $this->_lifetimelimit));
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doFetchMultiple(array $keys)
    {
        $redis = $this->_slave ? $this->_slave : $this->_redis;

        $fetchedItems = $redis->mget(array_map(function ($id) {
            return self::PREFIX_KEY.$id;
        }, $keys));

        $decoded = array_map([$this, '_decodeData'], array_filter(array_combine($keys, $fetchedItems)));

        if ($this->_autoExpireLifetime === 0 || !$this->_autoExpireRefreshOnLoad) {
            array_map([$this, '_applyAutoExpire'], $keys);
        }

        return $decoded;
    }

    /**
     * {@inheritdoc}
     */
    protected function doContains($id)
    {
        // Don't use slave for this since `doContains`/`test` is usually used for locking
        return $this->_redis->exists($id);
    }

    protected function _encodeData($data, $level)
    {
        return parent::_encodeData(json_encode($data), $level);
    }

    protected function _decodeData($data)
    {
        return json_decode(parent::_decodeData($data), true);
    }

    /**
     * {@inheritdoc}
     */
    protected function doSave($id, $data, $lifeTime = 0)
    {
        $data = $this->_encodeData($data, $this->_compressData);
        $lifetime = $this->_getAutoExpiringLifetime($lifeTime, $id);
        $lifeTime = min($lifetime, self::MAX_LIFETIME);

        if ($lifeTime > 0) {
            $response = $this->_redis->setex($id, $lifeTime, $data);
        } else {
            $response = $this->_redis->set($id, $data);
        }

        return $response === true;
    }

    /**
     * {@inheritdoc}
     */
    protected function doDelete($id)
    {
        return $this->_redis->del($id) >= 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function doFlush()
    {
        $response = $this->_redis->flushdb();

        return $response === true || $response == 'OK';
    }

    /**
     * {@inheritdoc}
     */
    protected function doGetStats()
    {
        //$redis = $this->_slave ? $this->_slave : $this->_redis;
        $info = $this->_redis->info();

        return array(
            Cache::STATS_HITS              => $info['Stats']['keyspace_hits'],
            Cache::STATS_MISSES            => $info['Stats']['keyspace_misses'],
            Cache::STATS_UPTIME            => $info['Server']['uptime_in_seconds'],
            Cache::STATS_MEMORY_USAGE      => $info['Memory']['used_memory'],
            Cache::STATS_MEMORY_AVAILABLE  => false
        );
    }
}

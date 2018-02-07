<?php

namespace SV\RedisCache;

/**
 * Redis adapter for XenForo2 & Doctrine
 *
 */

use Doctrine\Common\Cache\Cache;

require_once('Credis/Client.php');
require_once('Credis/Sentinel.php');
class Redis  extends Cm_Cache_Backend_Redis
{
    protected $useIgbinary = false;

    /**
     * Redis constructor.
     * @param array $options
     */
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

        $igbinaryPresent = is_callable('igbinary_serialize') && \is_callable('igbinary_unserialize');
        $this->useIgbinary = $igbinaryPresent && (empty($options['serializer']) || \utf8_strtolower($options['serializer']) == 'igbinary');

        if ( empty($options['server']) ) {
            $options['server'] = 'localhost';
        }

        parent::__construct($options);
    }

    /**
     * @param array|null $ips
     * @return array
     */
    protected function getLocalIps(array $ips = null)
    {
        if (!is_array($ips))
        {
            // I can't believe there isn't a better way
            try
            {
                $output = shell_exec("hostname --all-ip-addresses");
            }
            catch(\Exception $e) { $output = ''; }
            if ($output)
            {
                $ips = array_fill_keys(array_filter(array_map('trim', (explode(' ', $output)))), true);
            }
        }
        return $ips;
    }

    /**
     * @param array|null $ips
     * @param \Credis_Client[] $slaves
     * @param $master
     * @return \Credis_Client|null
     */
    protected function selectLocalRedis(array $ips = null, array $slaves, $master)
    {
        if ($ips)
        {
            /* @var $slave \Credis_Client */
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

    /**
     * @param \Credis_Client[] $slaves
     * @param $master
     * @return \Credis_Client|null
     */
    public function preferLocalSlave(array $slaves, $master)
    {
        $ips = $this->getLocalIps();
        return $this->selectLocalRedis($ips, $slaves, $master);
    }

    /**
     * @param \Credis_Client[] $slaves
     * @param $master
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
            catch(\Exception $e) { $output = ''; }
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

    /**
     * @param \Credis_Client[] $slaves
     * @param $master
     * @return \Credis_Client|null
     */
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

    /**
     * @return int
     */
    public function getCompressThreshold()
    {
        return $this->_compressThreshold;
    }

    /**
     * @param int $value
     */
    public function setCompressThreshold($value)
    {
        $this->_compressThreshold = $value;
    }

    /**
     * @param string $data
     * @return string
     */
    public function DecodeData($data)
    {
        return $this->_decodeData($data);
    }

    /**
     * @param bool $allowSlave
     * @return \Credis_Client
     */
    public function getCredis($allowSlave = false)
    {
        if ($allowSlave && $this->_slave)
        {
            return $this->_slave;
        }
        return $this->_redis;
    }

    /**
     * @return \Credis_Client
     */
    public function getSlaveCredis()
    {
        return $this->_slave;
    }

    /**
     * @param \Credis_Client $slave
     */
    public function setSlaveCredis($slave)
    {
        $this->_slave = $slave;
    }

    /**
     * @return bool
     */
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
            $data = $this->_slave->get($id);
        } else {
            $data = $this->_redis->get($id);
        }
        if ($data === null || $data === false) {
            return false;
        }

        $decoded = $this->_decodeData($data);

        if ($this->_autoExpireLifetime === 0 || !$this->_autoExpireRefreshOnLoad) {
            return $decoded;
        }

        $this->_applyAutoExpire($id);


        return $decoded;
    }

    /**
     * {@inheritdoc}
     */
    protected function doFetchMultiple(array $keys)
    {
        $redis = $this->_slave ? $this->_slave : $this->_redis;

        $fetchedItems = $redis->mget($keys);

        $decoded = array_map([$this, '_decodeData'], array_filter(array_combine($keys, $fetchedItems), function ($data){
            return $data !== null && $data !== false;
        }));
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

    /**
     * @param string $data
     * @param int $level
     * @return string
     */
    protected function _encodeData($data, $level)
    {
        // XF stores binary data as strings which causes issues using json for serialization
        $data = $this->useIgbinary ? @igbinary_serialize($data) : @serialize($data);
        return parent::_encodeData($data, $level);
    }

    /**
     * @param string $data
     * @return mixed
     */
    protected function _decodeData($data)
    {
        $data = parent::_decodeData($data);
        $data = $this->useIgbinary ? @igbinary_unserialize($data) : @unserialize($data);
        return $data;
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
            $response = $this->_redis->set($id, $data, $lifeTime);
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

<?php
/**
 * @noinspection PhpMissingParamTypeInspection
 * @noinspection PhpMissingReturnTypeInspection
 */

/*
==New BSD License==

Copyright (c) 2013, Colin Mollenhour
Copyright (c) 2017-2021, "Xon"
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright
      notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright
      notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * The name of Colin Mollenhour may not be used to endorse or promote products
      derived from this software without specific prior written permission.
    * The class name must remain as Cm_Cache_Backend_Redis.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

namespace SV\RedisCache;


/**
 * Redis adapter baseline
 *
 * @copyright  Copyright (c) 2013 Colin Mollenhour (http://colin.mollenhour.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @author     Colin Mollenhour (http://colin.mollenhour.com)
 */
abstract class Cm_Cache_Backend_Redis extends CacheProvider
{
    const PREFIX_KEY = '';

    const MAX_LIFETIME            = 2592000; /* Redis backend limit */
    const COMPRESS_PREFIX         = ":\x1f\x8b";
    const DEFAULT_CONNECT_TIMEOUT = 2.5;
    const DEFAULT_CONNECT_RETRIES = 1;

    /** @var \Credis_Client */
    protected $_redis;

    /** @var int */
    protected $_lifetimelimit = self::MAX_LIFETIME; /* Redis backend limit */

    /** @var int|bool */
    protected $_compressData = 1;

    /** @var int */
    protected $_compressThreshold = 20480;

    /** @var string */
    protected $_compressionLib;

    /** @var string */
    protected $_compressPrefix;

    /** @var bool */
    protected $_useLua = true;

    /** @var integer */
    protected $_autoExpireLifetime = 0;

    /** @var string */
    protected $_autoExpirePattern = '/REQEST/';

    /** @var boolean */
    protected $_autoExpireRefreshOnLoad = false;

    /**
     * Lua's unpack() has a limit on the size of the table imposed by
     * the number of Lua stack slots that a C function can use.
     * This value is defined by LUAI_MAXCSTACK in luaconf.h and for Redis it is set to 8000.
     *
     * @see https://github.com/antirez/redis/blob/b903145/deps/lua/src/luaconf.h#L439
     * @var int
     */
    protected $_luaMaxCStack = 5000;

    /**
     * If 'retry_reads_on_master' is truthy then reads will be retried against master when slave returns "(nil)" value
     *
     * @var boolean
     */
    protected $_retryReadsOnMaster = false;

    /**
     * @var \stdClass
     */
    protected $_clientOptions;

    /**
     * If 'load_from_slaves' is truthy then reads are performed on a randomly selected slave server
     *
     * @var \Credis_Client
     */
    protected $_slave;

    /**
     * @param array $options
     * @return \stdClass
     */
    protected function getClientOptions($options = [])
    {
        $clientOptions = new \stdClass();
        $clientOptions->forceStandalone = isset($options['force_standalone']) && $options['force_standalone'];
        $clientOptions->connectRetries = (int)($options['connect_retries'] ?? self::DEFAULT_CONNECT_RETRIES);
        $clientOptions->readTimeout = isset($options['read_timeout']) ? \floatval($options['read_timeout']) : null;
        $clientOptions->password = isset($options['password']) ? \strval($options['password']) : null;
        $clientOptions->username = isset($options['username']) ? \strval($options['username']) : null;
        $clientOptions->database = (int)($options['database'] ?? 0);
        $clientOptions->persistent = isset($options['persistent']) ? $options['persistent'] . '_' . $clientOptions->database : '';
        $clientOptions->timeout =  isset($options['timeout']) ? \floatval($options['timeout']) : self::DEFAULT_CONNECT_TIMEOUT;
        $clientOptions->tlsOptions = (array)($options['tlsOptions'] ?? []);

        return $clientOptions;
    }

    /**
     * Cm_Cache_Backend_Redis constructor.
     *
     * @param array $options
     * @throws \CredisException
     */
    public function __construct($options = [])
    {
        if (empty($options['server']))
        {
            throw new \CredisException('Redis \'server\' not specified.');
        }

        $this->_clientOptions = $this->getClientOptions($options);

        // If 'sentinel_master' is specified then server is actually sentinel and master address should be fetched from server.
        $sentinelMaster = empty($options['sentinel_master']) ? null : $options['sentinel_master'];
        if ($sentinelMaster)
        {
            $sentinelClientOptions = isset($options['sentinel']) && \is_array($options['sentinel'])
                ? $this->getClientOptions($options['sentinel'] + $options)
                : $this->_clientOptions;
            $servers = \is_array($options['server']) ? $options['server'] : \preg_split('/\s*,\s*/', \trim($options['server']), -1, PREG_SPLIT_NO_EMPTY);
            $sentinel = null;
            $exception = null;
            for ($i = 0; $i <= $sentinelClientOptions->connectRetries; $i++) // Try each sentinel in round-robin fashion
            {
                foreach ($servers as $server)
                {
                    try
                    {
                        $sentinelClient = new \Credis_Client($server, 26379, $sentinelClientOptions->timeout, $sentinelClientOptions->persistent);
                        $sentinelClient->forceStandalone();
                        $sentinelClient->setMaxConnectRetries(0);
                        if ($sentinelClientOptions->readTimeout)
                        {
                            $sentinelClient->setReadTimeout($sentinelClientOptions->readTimeout);
                        }
                        if ($sentinelClientOptions->password)
                        {
                            $sentinelClient->auth($sentinelClientOptions->password, $sentinelClientOptions->username ?? null) or $this->throwException('Unable to authenticate with the redis sentinel.');
                        }
                        $sentinel = new \Credis_Sentinel($sentinelClient);
                        $sentinel
                            ->setClientTimeout($this->_clientOptions->timeout)
                            ->setClientPersistent($this->_clientOptions->persistent);
                        $redisMaster = $sentinel->getMasterClient($sentinelMaster);
                        $this->_applyClientOptions($redisMaster);

                        // Verify connected server is actually master as per Sentinel client spec
                        if (!empty($options['sentinel_master_verify']))
                        {
                            $roleData = $redisMaster->role();
                            if (!$roleData || $roleData[0] !== 'master')
                            {
                                usleep(100000); // Sleep 100ms and try again
                                $redisMaster = $sentinel->getMasterClient($sentinelMaster);
                                $this->_applyClientOptions($redisMaster);
                                $roleData = $redisMaster->role();
                                if (!$roleData || $roleData[0] !== 'master')
                                {
                                    throw new \CredisException('Unable to determine master redis server.');
                                }
                            }
                        }

                        $this->_redis = $redisMaster;
                        break 2;
                    }
                    catch (\Exception $e)
                    {
                        unset($sentinelClient);
                        $exception = $e;
                    }
                }
            }
            if (!$this->_redis)
            {
                throw new \CredisException('Unable to connect to a redis sentinel: ' . $exception->getMessage(), 0, $exception);
            }

            // Optionally use read slaves - will only be used for 'load' operation
            if (!empty($options['load_from_slaves']))
            {
                $slaves = $sentinel->getSlaveClients($sentinelMaster);
                if ($slaves)
                {
                    if ($options['load_from_slaves'] === 2)
                    {
                        $slaves[] = $this->_redis; // Also send reads to the master
                    }
                    $slaveSelect = isset($options['slave_select_callable']) && \is_callable($options['slave_select_callable']) ? $options['slave_select_callable'] : null;
                    if ($slaveSelect)
                    {
                        $slave = $slaveSelect($slaves, $this->_redis);
                    }
                    else
                    {
                        /** @var string $slaveKey */
                        $slaveKey = \array_rand($slaves);
                        $slave = $slaves[$slaveKey];
                    }
                    if ($slave instanceof \Credis_Client && $slave !== $this->_redis)
                    {
                        try
                        {
                            $this->_applyClientOptions($slave, true);
                            $this->_slave = $slave;
                        }
                        catch (\Exception $e)
                        {
                            // If there is a problem with first slave then skip 'load_from_slaves' option
                        }
                    }
                }
            }
            unset($sentinel);
        }

        // Direct connection to single Redis server and optional slaves
        else
        {
            $port = (int)($options['port'] ?? 6379);
            $this->_redis = new \Credis_Client($options['server'], $port, $this->_clientOptions->timeout, $this->_clientOptions->persistent);
            $this->_applyClientOptions($this->_redis);

            // Support loading from a replication slave
            if (isset($options['load_from_slave']))
            {
                if (\is_array($options['load_from_slave']))
                {
                    if (isset($options['load_from_slave']['server']))
                    {  // Single slave
                        $server = $options['load_from_slave']['server'];
                        $port = $options['load_from_slave']['port'];
                        $clientOptions = $this->getClientOptions($options['load_from_slave'] + $options);
                        $totalServers = 2;
                    }
                    else
                    {  // Multiple slaves
                        $slaveKey = \array_rand($options['load_from_slave']);
                        $slave = $options['load_from_slave'][$slaveKey];
                        $server = $slave['server'];
                        $port = $slave['port'];
                        $clientOptions = $this->getClientOptions($slave + $options);
                        $totalServers = \count($options['load_from_slave']) + 1;
                    }
                }
                else
                {  // String
                    $server = $options['load_from_slave'];
                    $port = 6379;
                    $clientOptions = $this->_clientOptions;

                    // If multiple addresses are given, split and choose a random one
                    if (\strpos($server, ',') !== false)
                    {
                        $slaves = \preg_split('/\s*,\s*/', $server, -1, PREG_SPLIT_NO_EMPTY);
                        /** @var string $slaveKey */
                        $slaveKey = \array_rand($slaves);
                        $server = $slaves[$slaveKey];
                        $port = null;
                        $totalServers = \count($slaves) + 1;
                    }
                    else
                    {
                        $totalServers = 2;
                    }
                }
                // Skip setting up slave if master is not write only and it is randomly chosen to be the read server
                $masterWriteOnly = isset($options['master_write_only']) ? (int)$options['master_write_only'] : false;
                if (\is_string($server) && $server && !(!$masterWriteOnly && rand(1, $totalServers) === 1))
                {
                    try
                    {
                        $slave = new \Credis_Client($server, $port, $clientOptions->timeout, $clientOptions->persistent);
                        $this->_applyClientOptions($slave, true, $clientOptions);
                        $this->_slave = $slave;
                    }
                    catch (\Exception $e)
                    {
                        // Slave will not be used
                    }
                }
            }
        }

        if (isset($options['compress_data']))
        {
            $this->_compressData = (int)$options['compress_data'];
        }

        if (isset($options['lifetimelimit']))
        {
            $this->_lifetimelimit = (int)\min($options['lifetimelimit'], self::MAX_LIFETIME);
        }

        if (isset($options['compress_threshold']))
        {
            $this->_compressThreshold = (int)$options['compress_threshold'];
            if ($this->_compressThreshold < 1)
            {
                $this->_compressThreshold = 1;
            }
        }

        if (isset($options['compression_lib']))
        {
            $this->_compressionLib = (string)$options['compression_lib'];
        }
        else if (\function_exists('snappy_compress'))
        {
            $this->_compressionLib = 'snappy';
        }
        else if (\function_exists('lz4_compress'))
        {
            $version = \phpversion("lz4");
            if (\version_compare($version, "0.3.0") < 0)
            {
                $this->_compressData = $this->_compressData > 1;
            }
            $this->_compressionLib = 'l4z';
        }
        else if (\function_exists('zstd_compress'))
        {
            $version = phpversion("zstd");
            if (\version_compare($version, "0.4.13") < 0)
            {
                $this->_compressData = $this->_compressData > 1;
            }
            $this->_compressionLib = 'zstd';
        }
        else if (\function_exists('lzf_compress'))
        {
            $this->_compressionLib = 'lzf';
        }
        else
        {
            $this->_compressionLib = 'gzip';
        }
        $this->_compressPrefix = \substr($this->_compressionLib, 0, 2) . self::COMPRESS_PREFIX;

        if (isset($options['use_lua']))
        {
            $this->_useLua = (bool)$options['use_lua'];
        }

        if (isset($options['retry_reads_on_master']))
        {
            $this->_retryReadsOnMaster = (bool)$options['retry_reads_on_master'];
        }

        if (isset($options['auto_expire_lifetime']))
        {
            $this->_autoExpireLifetime = (int)$options['auto_expire_lifetime'];
        }

        if (isset($options['auto_expire_pattern']))
        {
            $this->_autoExpirePattern = (string)$options['auto_expire_pattern'];
        }

        if (isset($options['auto_expire_refresh_on_load']))
        {
            $this->_autoExpireRefreshOnLoad = (bool)$options['auto_expire_refresh_on_load'];
        }
    }

    protected function throwException($msg)
    {
        throw new \CredisException($msg);
    }

    /**
     * Apply common configuration to client instances.
     *
     * @param \Credis_Client $client
     * @param bool           $forceSelect
     * @param null           $clientOptions
     */
    protected function _applyClientOptions(\Credis_Client $client, $forceSelect = false, $clientOptions = null)
    {
        if ($clientOptions === null)
        {
            $clientOptions = $this->_clientOptions;
        }

        if ($clientOptions->forceStandalone)
        {
            $client->forceStandalone();
        }

        $client->setMaxConnectRetries($clientOptions->connectRetries);

        if ($clientOptions->readTimeout)
        {
            $client->setReadTimeout($clientOptions->readTimeout);
        }

        if ($clientOptions->password)
        {
            $client->auth($clientOptions->password, $clientOptions->username ?? null) or $this->throwException('Unable to authenticate with the redis server.');
        }

        if ($clientOptions->tlsOptions)
        {
            $client->setTlsOptions($clientOptions->tlsOptions);
        }

        // Always select database when persistent is used in case connection is re-used by other clients
        if ($forceSelect || $clientOptions->database || $client->getPersistence())
        {
            $client->select($clientOptions->database) or $this->throwException('The redis database could not be selected.');
        }
    }

    protected function _applyAutoExpire($id)
    {
        $matches = $this->_matchesAutoExpiringPattern($id);
        if ($matches)
        {
            $this->_redis->expire(self::PREFIX_KEY . $id, \min($this->_autoExpireLifetime, $this->_lifetimelimit));
        }
    }

    /**
     * Get the auto expiring lifetime.
     * Mainly a workaround for the issues that arise due to the fact that
     * Magento's Enterprise_PageCache module doesn't set any expiry.
     *
     * @param int    $lifetime
     * @param string $id
     * @return int Cache life time
     */
    protected function _getAutoExpiringLifetime($lifetime, $id)
    {
        if ($lifetime || !$this->_autoExpireLifetime)
        {
            // If it's already truthy, or there's no auto expire go with it.
            return $lifetime;
        }

        $matches = $this->_matchesAutoExpiringPattern($id);
        if (!$matches)
        {
            // Only apply auto expire for keys that match the pattern
            return $lifetime;
        }

        if ($this->_autoExpireLifetime > 0)
        {
            // Return the auto expire lifetime if set
            return $this->_autoExpireLifetime;
        }

        // Return whatever it was set to.
        return $lifetime;
    }

    protected function _matchesAutoExpiringPattern($id)
    {
        $matches = [];
        \preg_match($this->_autoExpirePattern, $id, $matches);

        return !empty($matches);
    }


    /**
     * @param string $data
     * @param int    $level
     * @return string
     * @throws \CredisException
     */
    protected function _encodeData($data, $level)
    {
        if ($this->_compressionLib && $level !== 0 && \strlen($data) >= $this->_compressThreshold)
        {
            switch ($this->_compressionLib)
            {
                case 'snappy':
                    /** @noinspection PhpUndefinedFunctionInspection */ $data = snappy_compress($data);
                    break;
                case 'lzf':
                    /** @noinspection PhpUndefinedFunctionInspection */ $data = lzf_compress($data);
                    break;
                case 'l4z':
                    /** @noinspection PhpUndefinedFunctionInspection */ $data = lz4_compress($data, $level);
                    break;
                case 'zstd':
                    /** @noinspection PhpUndefinedFunctionInspection */ $data = zstd_compress($data, $level);
                    break;
                case 'gzip':
                    $data = gzcompress($data, $level);
                    break;
                default:
                    throw new \CredisException("Unrecognized 'compression_lib'.");
            }
            if (!$data)
            {
                throw new \CredisException("Could not compress cache data.");
            }

            return $this->_compressPrefix . $data;
        }

        return $data;
    }

    /**
     * @param bool|string $data
     * @return string
     */
    protected function _decodeData($data)
    {
        try
        {
            if (\substr($data, 2, 3) === self::COMPRESS_PREFIX)
            {
                switch (\substr($data, 0, 2))
                {
                    case 'sn':
                        /** @noinspection PhpUndefinedFunctionInspection */ $data = \snappy_uncompress(\substr($data, 5));
                        break;
                    case 'lz':
                        /** @noinspection PhpUndefinedFunctionInspection */ $data = \lzf_decompress(\substr($data, 5));
                        break;
                    case 'l4':
                        /** @noinspection PhpUndefinedFunctionInspection */ $data = \lz4_uncompress(\substr($data, 5));
                        break;
                    case 'zs':
                        /** @noinspection PhpUndefinedFunctionInspection */ $data = \zstd_uncompress(\substr($data, 5));
                        break;
                    case 'gz':
                    case 'zc':
                        $data = \gzuncompress(\substr($data, 5));
                        break;
                }
            }
        }
        catch (\Exception $e)
        {
            // Some applications will capture the php error that these functions can sometimes generate and throw it as an Exception
            $data = false;
        }

        return $data;
    }

    /**
     * Required to pass unit tests
     *
     * @param string $id
     * @return void
     */
    public function ___expire($id)
    {
        $this->_redis->del(self::PREFIX_KEY . $id);
    }

    /**
     * Only for unit tests
     */
    public function ___scriptFlush()
    {
        $this->_redis->script('flush');
    }

}

<?php
$config = $config ?? [];
// setup redis caching
$config['cache']['sessions'] = true;
$config['cache']['enabled'] = true;
$config['cache']['provider'] = 'SV\RedisCache\Redis';
// all keys and their defaults
$config['cache']['config'] = [
        'server' => '127.0.0.1',
        'port' => 6379,
        'timeout' => 2.5,
        'persistent' => null,
        'force_standalone' => false,
        'connect_retries' => 1,
        'read_timeout' => null,
        'username' => null, // redis 6+
        'database' => 0,
        'compress_data' => 1,
        'lifetimelimit' => 2592000,
        'compress_threshold' => 20480,
        'compression_lib' => null, // dynamically select first of; snappy,lzf,l4z,gzip IF EMPTY/null
        'serializer' => 'igbinary', // to disable set to 'php'
        'retry_reads_on_primary' => true,
        // HA support
        'load_from_replica' => null, // config entry similar to $config['cache']['config'], except without HA options
        'load_from_replicas' => null, // config entry similar to $config['cache']['config'], except without HA options
        'sentinel_primary' => null,
        'sentinel_persistent' => null,
];
// single replica (has most of the details of config):
$config['cache']['config']['load_from_replica'] = [
        'server' => '127.0.0.1',
        'port' => 6378,
];

// minimal case for sentinel support (aka HA)
$config['cache']['config']['sentinel_primary'] = 'mymaster';
$config['cache']['config']['server'] = '127.0.0.1:26379';
$config['cache']['config']['load_from_replicas'] = false; // send readonly queries to the replicas
$config['cache']['config']['sentinel_persistent'] = null; // persistent connection option for the sentinel, but not the primary/replica

// minimal case
$config['cache']['config'] = [
        'server' => '127.0.0.1',
        'port' => 6379,
];

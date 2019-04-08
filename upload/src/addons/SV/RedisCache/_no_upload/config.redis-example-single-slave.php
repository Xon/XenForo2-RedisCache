<?php
// setup redis caching
$config['cache']['enabled'] = true;
$config['cache']['provider'] = 'SV\RedisCache\Redis';
// all keys and their defaults
$config['cache']['config'] = array(
        'server' => '127.0.0.1',
        'port' => 6379,
        'timeout' => 2.5,
        'persistent' => null,
        'force_standalone' => false,
        'connect_retries' => 1,
        'read_timeout' => null,
        'password' => null,
        'database' => 0,
        'compress_data' => 1,
        'lifetimelimit' => 2592000,
        'compress_threshold' => 20480,
        'compression_lib' => null, // dynamically select first of; snappy,lzf,l4z,gzip IF EMPTY/null
        'use_lua' => true,
        'serializer' => 'igbinary', // to disable set ot 'php'
        'retry_reads_on_master' => false,
        );
// single slave (has most of the details of config):
$config['cache']['config']['load_from_slave'] = array(
        'server' => '127.0.0.1',
        'port' => 6378,
        );

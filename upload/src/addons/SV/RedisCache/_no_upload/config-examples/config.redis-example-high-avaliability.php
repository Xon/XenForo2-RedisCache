<?php
// setup redis caching
$config['cache']['sessions'] = true;
$config['cache']['enabled'] = true;
$config['cache']['provider'] = \SV\RedisCache\Redis::class;
// all keys and their defaults
$config['cache']['config'] = [
    'server' => '127.0.0.1',
    'port' => 6379,
];

// minimal case for sentinel support (aka HA)
$config['cache']['config']['sentinel_primary'] = 'mymaster';
$config['cache']['config']['server'] = '127.0.0.1:26379';
$config['cache']['config']['load_from_replicas'] = true; // send readonly queries to the replicas
$config['cache']['config']['sentinel_persistent'] = null; // persistent connection option for the sentinel, but not the primary/replica

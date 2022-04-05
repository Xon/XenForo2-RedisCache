<?php
// setup redis caching
$config['cache']['sessions'] = true;
$config['cache']['enabled'] = true;
$config['cache']['provider'] = 'SV\RedisCache\Redis';
// all keys and their defaults
$config['cache']['config'] = [
        'server' => '127.0.0.1',
        'port' => 6379,
];
// single slave (has most of the details of config):
$config['cache']['config']['load_from_slave'] = [
        'server' => '127.0.0.1',
        'port' => 6378,
];

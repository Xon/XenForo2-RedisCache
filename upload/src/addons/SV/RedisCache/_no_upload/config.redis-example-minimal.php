<?php
// setup redis caching
$config['cache']['sessions'] = true;
$config['cache']['enabled'] = true;
$config['cache']['provider'] = 'SV\RedisCache\Redis';
$config['cache']['config'] = [
    'server' => '127.0.0.1',
    'port' => 6379,
];

# XenForo2-RedisCache
XenForo + Redis + Glue code

This add-on is based off [Cm_Cache_Backend_Redis](https://github.com/colinmollenhour/Cm_Cache_Backend_Redis) to provide a Doctrine Cache target for [Redis](http://redis.io/).

## Igbinary Support

If igbinary is usable, this add-on defaults to using it as a serialize. To supress this;
```
$config['cache']['config']['serializer'] = 'php';
```

# Performance
For best performance use: [phpredis PECL extension](http://pecl.php.net/package/redis)

Sample Redis configuration for XenForo:
```
$config['cache']['enabled'] = true;
$config['cache']['provider'] = 'SV\RedisCache\Redis';
$config['cache']['config'] = array(
        'server' => '127.0.0.1',
        'port' => 6379,
        'connect_retries' => 2,
        'use_lua' => true,
        'compress_data' => 2,
        'read_timeout' => 1,
        'timeout' => 1,
    );
```

# Master/Slave
Loding Data from a single slave is possible, or alternatively Redis Sentinel support can be used  high-availability. See http://redis.io/topics/sentinel for more information.

Single Slave:
$config['cache']['config']['load_from_slave'] = array(
        'server' => '127.0.0.1',
        'port' => 6378,
        'connect_retries' => 2,
        'use_lua' => true,
        'compress_data' => 2,
        'read_timeout' => 1,
        'timeout' => 1,
    );


Redis Sentinel Enable with:
```
$config['cache']['config']['sentinel_master_set'] = 'mymaster';
$config['cache']['config']['server'] = '127.0.0.1:26379';
```
'server' now points to a comma delimited list of sentinal servers to find the master. Note; the port must be explicitly listed

To load data from slaves use;
```
$config['cache']['config']['load_from_slaves'] = true;
```
This will prefer any slave with an IP matching an IP on the machine. This is fetched via the non-portable method:```shell_exec("hostname --all-ip-addresses")```
To run on windows, or if shell_exec is disabled, you must define an 'slave-select' attribute.


By default, a local slave is preferred, this can be changed by setting:
```
$config['cache']['config']['slave-select'] = function (array $slaves) { 
        $slaveKey = array_rand($slaves, 1);
        return $slaves[$slaveKey];
};
```
Setting to false (or some non-callable) will fall back to a random slave.

Licensing:

New BSD License:
- Cm_Cache_Backend_Redis
- Credis

MIT Licensed:
- XenForo Addon code

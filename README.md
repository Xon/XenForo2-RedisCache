# XenForo2-RedisCache
XenForo + Redis + Glue code

This add-on is based off [Cm_Cache_Backend_Redis](https://github.com/colinmollenhour/Cm_Cache_Backend_Redis) to provide a Doctrine Cache target for [Redis](http://redis.io/).

Supports compression algos; gzip, lzf, lz4 (as l4z), snappy and zstd

## Phpstorm Lua support

The plugin https://plugins.jetbrains.com/plugin/14698-luanalysis can be used to provide syntax highlighting and inspector support for lua

## Igbinary Support

If igbinary is usable, this add-on defaults to using it as a serialize. To supress this;
```php
$config['cache']['config']['serializer'] = 'php';
```

# Performance
For best performance use: [phpredis PECL extension](http://pecl.php.net/package/redis)

Sample Redis configuration for XenForo:
```php
$config['cache']['enabled'] = true;
$config['cache']['sessions'] = true;
$config['cache']['provider'] = 'SV\RedisCache\Redis';
$config['cache']['config'] = [
        'server' => '127.0.0.1',
        'port' => 6379,
        'connect_retries' => 2,
        'use_lua' => true,
        'compress_data' => 2,
        'read_timeout' => 1,
        'timeout' => 1,
];
```

## Authentication support
Redis supports username/password authentication.
This is most common in cloud environments which use redis 6 (ie with a username), while older redis only supports just a password
```php
$config['cache']['config']['username'] = 'myUsername'; // requires redis 6+, or for cloud redis installations
$config['cache']['config']['password'] = '....';
```

## SSL/TLS support

```php
$config['cache']['config']['server'] = 'ssl://127.0.0.1';
// See https://www.php.net/manual/en/context.ssl.php for details
$config['cache']['config']['tlsOptions'] = [
   'SNI_enabled' => true,
];
```

# Primary/replica
Loading Data from a single replica is possible, or alternatively Redis Sentinel support can be used  high-availability. See http://redis.io/topics/sentinel for more information.
```php
// Single Replica:
$config['cache']['config']['load_from_replica'] = [
        'server' => '127.0.0.1',
        'port' => 6378,
        'connect_retries' => 2,
        'use_lua' => true,
        'compress_data' => 2,
        'read_timeout' => 1,
        'timeout' => 1,
        'retry_reads_on_primary' => true,
];
```

If 'retry_reads_on_primary' is truthy then reads will be retried against primary when replica returns "(nil)" value (ie replica is not yet initialized).

Redis Sentinel Enable with:
```php
$config['cache']['config']['sentinel_primary'] = 'mymaster';
$config['cache']['config']['server'] = '127.0.0.1:26379';
```
'server' now points to a comma delimited list of sentinal servers to find the primary. Note; the port must be explicitly listed

To load data from replicas use;
```php
$config['cache']['config']['load_from_replicas'] = true;
```
This will prefer any replica with an IP matching an IP on the machine. This is fetched via the non-portable method:```shell_exec("hostname --all-ip-addresses")```
To run on windows, or if shell_exec is disabled, you must define an 'replica_select_callable' attribute.


By default, a local replica is preferred, this can be changed by setting:
```php
$config['cache']['config']['replica_select_callable'] = function (array $replicas) { 
        $replicaKey = \array_rand($replicas);
        return $replicas[$replicaKey];
};
```
Setting to false (or some non-callable) will fall back to a random replica.

Licensing:

New BSD License:
- Cm_Cache_Backend_Redis
- Credis

MIT Licensed:
- XenForo Addon code

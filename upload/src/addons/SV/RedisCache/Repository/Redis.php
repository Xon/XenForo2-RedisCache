<?php
/**
 * @noinspection DuplicatedCode
 */

namespace SV\RedisCache\Repository;

use Credis_Client;
use XF\Mvc\Entity\Repository;
use XF\Mvc\Reply\View;
use function microtime;

class Redis extends Repository
{
    /**
     * @return Redis|Repository
     */
    public static function instance()
    {
        return \XF::repository('SV\RedisCache:Redis');
    }

    /**
     * Inserts redis info parameters into a view.
     *
     * @param View        $view
     * @param string|null $context
     * @param int|null    $replicaId
     */
    public function insertRedisInfoParams(View $view, string $context = null, int $replicaId = null)
    {
        $mainConfig = \XF::app()->config()['cache'];
        $redisInfo = [];
        $contexts = [];
        if ($context === null)
        {
            $contexts[''] = $mainConfig;
            if (isset($mainConfig['context']))
            {
                $contexts = $contexts + $mainConfig['context'];
            }
        }
        else
        {
            if (\strlen($context) === 0)
            {
                $contexts[$context] = $mainConfig;
            }
            else if (isset($mainConfig['context'][$context]))
            {
                $contexts[$context] = $mainConfig['context'][$context];
            }
        }

        foreach ($contexts as $contextLabel => $config)
        {
            $cache = \XF::app()->cache($contextLabel, false);
            if (($cache instanceof \SV\RedisCache\Redis) &&
                ($credis = $cache->getCredis()))
            {
                $useLua = $cache->useLua();
                $redisInfo[$contextLabel] = $this->addRedisInfo($config, $credis->info(), $useLua);
                $replicas = $redisInfo[$contextLabel]['replicas'];
                if ($replicaId !== null)
                {
                    if (isset($replicas[$replicaId]))
                    {
                        $replicaDetails = $replicas[$replicaId];
                        $database = empty($config['config']['database']) ? 0 : (int)$config['config']['database'];
                        $password = empty($config['config']['password']) ? null : $config['config']['password'];
                        $timeout = empty($config['config']['timeout']) ? null : $config['config']['timeout'];
                        $persistent = empty($config['config']['persistent']) ? null : $config['config']['persistent'];
                        $forceStandalone = empty($config['config']['force_standalone']) ? null : $config['config']['force_standalone'];

                        // query the replica for stats
                        $replicaClient = new Credis_Client($replicaDetails['ip'], $replicaDetails['port'], $timeout, $persistent, $database, $password);
                        if ($forceStandalone)
                        {
                            $replicaClient->forceStandalone();
                        }
                        $redisInfo[$contextLabel] = $this->addRedisInfo($config, $replicaClient->info(), $useLua);

                        $redisInfo[$context]['replicaId'] = $replicaId;
                    }
                    else
                    {
                        unset($redisInfo[$contextLabel]);
                    }
                }
            }
        }

        if ($redisInfo)
        {
            $view->setParam('cacheContextSingle', $context !== null);
            $view->setParam('cacheContext', $context);
            $view->setParam('redisReplicaId', $replicaId);
            $view->setParam('redis', $redisInfo);
        }
    }

    /**
     * Processes redis info and adds as parameter to view.
     *
     * @param array $config
     * @param array $data
     * @param bool  $useLua
     * @return array
     */
    private function addRedisInfo(array $config, array $data, bool $useLua = true): array
    {
        $database = 0;
        $replicas = [];
        $db = [];

        if (!empty($data))
        {
            $database = empty($config['config']['database']) ? 0 : (int)$config['config']['database'];
            foreach ($data as $key => $value)
            {
                if (\preg_match('/^db(\d+)$/i', $key, $matches))
                {
                    $index = $matches[1];
                    unset($data[$key]);
                    $list = \explode(',', $value);
                    $dbStats = [];
                    foreach ($list as $item)
                    {
                        $parts = \explode('=', $item);
                        $dbStats[$parts[0]] = $parts[1];
                    }

                    $db[$index] = $dbStats;
                }
            }
            // got replicas
            if (isset($data['connected_slaves']) && isset($data['master_repl_offset']))
            {
                foreach ($data as $key => $value)
                {
                    if (\preg_match('/^slave(\d+)$/i', $key, $matches))
                    {
                        $index = $matches[1];
                        unset($data[$key]);
                        $list = \explode(',', $value);
                        $dbStats = [];
                        foreach ($list as $item)
                        {
                            $parts = \explode('=', $item);
                            $dbStats[$parts[0]] = $parts[1];
                        }

                        $replicas[$index] = $dbStats;
                    }
                }
            }
        }

        $igbinaryPresent = \is_callable('igbinary_serialize') && \is_callable('igbinary_unserialize');
        $data['serializer'] = empty($config['config']['serializer']) ? ($igbinaryPresent ? 'igbinary' : 'php') : $config['config']['serializer'];
        $data['replicas'] = $replicas;
        $data['db'] = $db;
        $data['db_default'] = $database;
        $data['lua'] = $useLua;
        $data['phpredis'] = phpversion('redis');
        $data['HasIOStats'] = isset($data['instantaneous_input_kbps']) && isset($data['instantaneous_output_kbps']);

        return $data;
    }

    public function visitCacheByPattern(string $pattern, &$cursor, float $maxRunTime, callable $func, int $batch = 1000): void
    {
        $cache = \XF::app()->cache();

        if (!($cache instanceof \SV\RedisCache\Redis))
        {
            $cursor = null;
            return;
        }

        $startTime = microtime(true);
        $credis = $cache->getCredis();
        $pattern = $cache->getNamespacedId($pattern) . '*';
        $dbSize = $credis->dbsize() ?: 100000;
        // indicate to the redis instance would like to process X items at a time.
        $loopGuardSize = ($dbSize / $batch) + 10;
        // only valid values for cursor are null (the stack turns it into a 0) or whatever scan return
        // prevent looping forever
        $loopGuard = $loopGuardSize;
        do
        {
            $keys = $credis->scan($cursor, $pattern, $batch);
            $loopGuard--;
            if ($keys === false)
            {
                $cursor = null;
                break;
            }

            $func($credis, $keys);

            if ($maxRunTime > 0 && microtime(true) - $startTime > $maxRunTime)
            {
                return;
            }
        }
        while ($loopGuard > 0 && $cursor);
        // unexpected number of loops, just abort rather than risk looping forever
        if ($loopGuard <= 0)
        {
            $cursor = null;
        }
    }

    public function purgeCacheByPattern(string $pattern, ?int& $cursor, float $maxRunTime, int $batch = 1000): int
    {
        $done = 0;
        $this->visitCacheByPattern($pattern, $cursor, $maxRunTime, function(\Credis_Client $credis, array $keys) use (&$done) {
            $credis->pipeline();
            /** @var array<string> $keys */
            foreach ($keys as $key)
            {
                $done++;
                $credis->del($key);
            }
            $credis->exec();
        }, $batch);
        return $done;
    }

    public function expireCacheByPattern(int $expiryInSeconds, string $pattern, ?int& $cursor, float $maxRunTime, int $batch = 1000): int
    {
        $done = 0;
        $this->visitCacheByPattern($pattern, $cursor, $maxRunTime, function(\Credis_Client $credis, array $keys) use (&$done, $expiryInSeconds) {
            $credis->pipeline();
            /** @var array<string> $keys */
            foreach ($keys as $key)
            {
                $done++;
                $credis->expire($key, $expiryInSeconds);
            }
            $credis->exec();
        }, $batch);
        return $done;
    }
}

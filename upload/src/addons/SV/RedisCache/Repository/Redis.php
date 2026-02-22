<?php

namespace SV\RedisCache\Repository;

use Credis_Client;
use Doctrine\Common\Cache\CacheProvider;
use SV\RedisCache\Job\PurgeRedisCacheByPattern;
use SV\RedisCache\Repository\Redis as RedisRepo;
use XF\Entity\AdminNavigation as AdminNavigationEntity;
use XF\Mvc\Entity\Repository;
use XF\Mvc\Reply\View as ViewReply;
use function array_key_exists;
use function ceil;
use function explode;
use function is_callable;
use function microtime;
use function phpversion;
use function preg_match;
use function str_replace;
use function strlen;
use function strtoupper;
use function trim;

class Redis extends Repository
{
    /**
     * @deprecated
     */
    public static function instance(): self
    {
        return self::get();
    }

    /**
     * @noinspection RedundantSuppression
     * @noinspection PhpIncompatibleReturnTypeInspection
     */
    public static function get(): self
    {
        return \XF::repository('SV\RedisCache:Redis');
    }

    public function getRedisConnector(string $cacheContext = '', bool $fallbackToGlobal = true): ?\SV\RedisCache\Redis
    {
        $cache = \XF::app()->cache($cacheContext, $fallbackToGlobal);

        return $this->getRedisObj($cache);
    }

    /**
     * @param CacheProvider|\Symfony\Component\Cache\Adapter\AbstractAdapter|\Symfony\Component\Cache\Adapter\AdapterInterface $cache
     * @return \SV\RedisCache\Redis|null
     * @noinspection PhpFullyQualifiedNameUsageInspection
     */
    public function getRedisObj($cache): ?\SV\RedisCache\Redis
    {
        if (\XF::$versionId >= 2030000)
        {
            if ($cache instanceof CacheProvider)
            {
                $cache = $cache->getAdapter();
            }
        }

        if ($cache instanceof \SV\RedisCache\Redis)
        {
            return $cache;
        }

        return null;
    }

    /**
     * Inserts redis info parameters into a view.
     *
     * @param ViewReply   $view
     * @param string|null $context
     * @param int|null    $replicaId
     * @throws \CredisException
     */
    public function insertRedisInfoParams(ViewReply $view, ?string $context = null, ?int $replicaId = null)
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
            if (strlen($context) === 0)
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
            $cache = RedisRepo::get()->getRedisConnector($contextLabel, false);
            if (($cache !== null) && ($credis = $cache->getCredis()))
            {
                $redisInfo[$contextLabel] = $this->addRedisInfo($config, $credis->info());
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
                        $redisInfo[$contextLabel] = $this->addRedisInfo($config, $replicaClient->info());

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
            $redisInfo = $this->shimRedisInfo($redisInfo);

            $view->setParam('cacheContextSingle', $context !== null);
            $view->setParam('cacheContext', $context);
            $view->setParam('redisReplicaId', $replicaId);
            $view->setParam('redis', $redisInfo);
        }
    }

    protected function shimRedisInfo(array $redisInfo): array
    {
        return $redisInfo;
    }

    protected $decimalUnits = [
        'KB' => 1000 ** 1,
        'MB' => 1000 ** 2,
        'GB' => 1000 ** 3,
        'TB' => 1000 ** 4,
        'PB' => 1000 ** 5,
    ];

    protected $binaryUnits = [
        'B'   => 1024 ** 0,
        'K'   => 1024 ** 1,
        'KIB' => 1024 ** 1,
        'M'   => 1024 ** 2,
        'MIB' => 1024 ** 2,
        'G'   => 1024 ** 3,
        'GIB' => 1024 ** 3,
        'T'   => 1024 ** 4,
        'TIB' => 1024 ** 4,
        'P'   => 1024 ** 5,
        'PIB' => 1024 ** 5,
    ];

    /**
     * @param float|int|string|null $memValue
     * @return int
     */
    public function parseMemoryValue($memValue): int
    {
        if (!is_string($memValue))
        {
            return (int)$memValue;
        }

        $memValue = trim($memValue);
        if ($memValue === '' || !preg_match('/^(\d+(?:\.\d+)?)\s*([a-z])?$/i', $memValue, $matches))
        {
            return 0;
        }

        $value = $this->stringToFloatInt($matches[1]);

        $multiplier = strtoupper($matches[2] ?? 'B');
        $multiplier = $this->decimalUnits[$multiplier] ?? null;
        if ($multiplier === null)
        {
            $multiplier = $this->binaryUnits[$multiplier] ?? 1;
        }

        return (int)($value * $multiplier);
    }

    /**
     * @param string|int|float $value
     * @param int|float $default
     * @return int|float
     */
    public function stringToFloatInt($value, $default = 0)//: int|float
    {
        if (is_int($value) || is_float($value))
        {
            return $value;
        }

        if (!is_numeric($value))
        {
            return $default;
        }

        try
        {
            /** @noinspection PhpWrongStringConcatenationInspection */
            return strval(floatval($value)) + 0;
        }
        catch (\Throwable $e)
        {
            return $default;
        }
    }

    /**
     * Processes redis info and adds as parameter to view.
     *
     * @param array $config
     * @param array $data
     * @return array
     */
    private function addRedisInfo(array $config, array $data): array
    {
        $database = 0;
        $replicas = [];
        $db = [];

        if (!empty($data))
        {
            $database = empty($config['config']['database']) ? 0 : (int)$config['config']['database'];
            foreach ($data as $key => $value)
            {
                if (preg_match('/^db(\d+)$/i', $key, $matches))
                {
                    $index = $matches[1];
                    unset($data[$key]);
                    $list = explode(',', $value);
                    $dbStats = [];
                    foreach ($list as $item)
                    {
                        $parts = explode('=', $item);
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
                    if (preg_match('/^slave(\d+)$/i', $key, $matches))
                    {
                        $index = $matches[1];
                        unset($data[$key]);
                        $list = explode(',', $value);
                        $dbStats = [];
                        foreach ($list as $item)
                        {
                            $parts = explode('=', $item);
                            $dbStats[$parts[0]] = $parts[1];
                        }

                        $replicas[$index] = $dbStats;
                    }
                }
            }
        }

        $igbinaryPresent = is_callable('igbinary_serialize') && is_callable('igbinary_unserialize');
        $data['serializer'] = empty($config['config']['serializer']) ? ($igbinaryPresent ? 'igbinary' : 'php') : $config['config']['serializer'];
        $data['replicas'] = $replicas;
        $data['db'] = $db;
        $data['db_default'] = $database;
        $data['phpredis'] = phpversion('redis');

        $data['HasIOStats'] = isset($data['instantaneous_input_kbps']) && isset($data['instantaneous_output_kbps']);
        $this->extractRedisVariant($data);

        return $data;
    }

    /** @noinspection SpellCheckingInspection */
    protected $memorySizeFields = [
        'maxmemory',
    ];

    /** @noinspection SpellCheckingInspection */
    protected function extractRedisVariant(array &$data)
    {
        // just extract memory field differences for all varaints
        foreach ($this->memorySizeFields as $field)
        {
            if (array_key_exists($field, $data))
            {
                $data[$field] = $this->parseMemoryValue($data[$field]);
            }
        }

        $type = $this->getRedisVariantType($data) ?? 'Redis';
        $data = $this->applyRedisVariantToData($type, $data);
    }

    public const Redis_Variant_KeyDb = 'KeyDb';
    public const Redis_Variant_Dragonfly = 'Dragonfly';
    /** @noinspection SpellCheckingInspection */
    public const Redis_Variant_Valkey = 'Valkey';

    protected function getRedisVariantType(array $data): ?string
    {
        if (preg_match('#/keydb-server$#', $data['executable'] ?? ''))
        {
            return self::Redis_Variant_KeyDb;
        }
        if (isset($data['dragonfly_version']))
        {
            return self::Redis_Variant_Dragonfly;
        }
        if (isset($data['valkey_version']))
        {
            return self::Redis_Variant_Valkey;
        }

        return null;
    }

    /** @noinspection SpellCheckingInspection */
    protected function applyRedisVariantToData(string $type, array $data): array
    {
        $data['type'] = $type;

        switch ($type)
        {
            case self::Redis_Variant_Dragonfly:
                $data['redis_version'] = str_replace('df-v', '', $data['dragonfly_version']);
                $data['HasIOStats'] = ($data['instantaneous_input_kbps'] ?? -1) !== -1 && ($data['instantaneous_output_kbps'] ?? -1) !== -1;
                return $data;
            case self::Redis_Variant_Valkey:
                $releaseStage = $data['valkey_release_stage'] ?? null;
                $data['redis_version'] = $data['valkey_version'] . ($releaseStage !== null ? '-' . $releaseStage : '');
                return $data;
            default:
                return $data;
        }
    }

    public function visitCacheByPattern(string $pattern, &$cursor, float $maxRunTime, callable $func, int $batch = 1000, $cache = null): void
    {
        $cache = $cache ?? \XF::app()->cache();

        // Redis php-ext uses 0 to signal stop, and null to signal start for the scan
        // Otherwise the cursor is to be considered an arbitrary blob
        if ($cursor === 0)
        {
            return;
        }

        $cache = $this->getRedisObj($cache);
        if ($cache === null)
        {
            $cursor = 0;
            return;
        }

        $startTime = microtime(true);
        $credis = $cache->getCredis();
        $pattern = $cache->getNamespacedId($pattern) . '*';
        $dbSize = $credis->dbsize() ?: 100000;
        // indicate to the redis instance would like to process X items at a time.
        $loopGuardSize = (int)ceil($dbSize / $batch) + 10;
        // prevent looping forever
        $loopGuard = $loopGuardSize;
        do
        {
            $keys = $credis->scan($cursor, $pattern, $batch);
            $loopGuard--;
            if ($keys === false)
            {
                $cursor = 0;
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
            $cursor = 0;
        }
    }

    public function purgeCacheByPattern(string $pattern, &$cursor, float $maxRunTime, int $batch = 1000, $cache = null): int
    {
        $done = 0;
        $this->visitCacheByPattern($pattern, $cursor, $maxRunTime, function(Credis_Client $credis, array $keys) use (&$done) {
            $credis->pipeline();
            /** @var array<string> $keys */
            foreach ($keys as $key)
            {
                $done++;
                $credis->del($key);
            }
            $credis->exec();
        }, $batch, $cache);
        return $done;
    }

    public function expireCacheByPattern(int $expiryInSeconds, string $pattern, &$cursor, float $maxRunTime, int $batch = 1000, $cache = null): int
    {
        $done = 0;
        $this->visitCacheByPattern($pattern, $cursor, $maxRunTime, function(Credis_Client $credis, array $keys) use (&$done, $expiryInSeconds) {
            $credis->pipeline();
            /** @var array<string> $keys */
            foreach ($keys as $key)
            {
                $done++;
                $credis->expire($key, $expiryInSeconds);
            }
            $credis->exec();
        }, $batch, $cache);
        return $done;
    }

    public function purgeThreadCountByForumDeferred(int $nodeId): void
    {
        if ($nodeId === 0)
        {
            return;
        }

        $key = 'svPurgeThreadCount.' . $nodeId;
        \XF::runOnce($key, function () use ($key, $nodeId) {
            PurgeRedisCacheByPattern::enqueue($key, 'forum_' . $nodeId . '_threadcount_');
        });
    }

    public function canViewServerInfo(): bool
    {
        if (\XF::$versionId < 2030470)
        {
            return true;
        }

        return \XF::visitor()->hasAdminPermission('serverInfo');
    }

    public function shimAdminNavigation(): void
    {
        /** @var AdminNavigationEntity|null $redisInfo */
        $redisInfo = \XF::app()->find('XF:AdminNavigation', 'redisInfo');
        if ($redisInfo === null)
        {
            return;
        }

        $redisInfo->admin_permission_id = \XF::$versionId >= 2030470 ? 'serverInfo' : '';
        $redisInfo->saveIfChanged();
    }
}

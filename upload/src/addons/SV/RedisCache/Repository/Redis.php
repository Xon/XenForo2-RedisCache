<?php
/**
 * @noinspection DuplicatedCode
 */

namespace SV\RedisCache\Repository;

use Credis_Client;
use XF\Mvc\Entity\Repository;
use XF\Mvc\Reply\View;

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
     * @param int|null    $slaveId
     */
    public function insertRedisInfoParams(View $view, string $context = null, int $slaveId = null)
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
            if ($cache &&
                $cache instanceof \SV\RedisCache\Redis &&
                $credis = $cache->getCredis(false))
            {
                $useLua = $cache->useLua();
                $redisInfo[$contextLabel] = $this->addRedisInfo($config, $credis->info(), $useLua);
                $slaves = $redisInfo[$contextLabel]['slaves'];
                if ($slaveId !== null)
                {
                    if (isset($slaves[$slaveId]))
                    {
                        $slaveDetails = $slaves[$slaveId];
                        $database = empty($config['config']['database']) ? 0 : (int)$config['config']['database'];
                        $password = empty($config['config']['password']) ? null : $config['config']['password'];
                        $timeout = empty($config['config']['timeout']) ? null : $config['config']['timeout'];
                        $persistent = empty($config['config']['persistent']) ? null : $config['config']['persistent'];
                        $forceStandalone = empty($config['config']['force_standalone']) ? null : $config['config']['force_standalone'];

                        // query the slave for stats
                        $slaveClient = new Credis_Client($slaveDetails['ip'], $slaveDetails['port'], $timeout, $persistent, $database, $password);
                        if ($forceStandalone)
                        {
                            $slaveClient->forceStandalone();
                        }
                        $redisInfo[$contextLabel] = $this->addRedisInfo($config, $slaveClient->info(), $useLua);

                        $redisInfo[$context]['slaveId'] = $slaveId;
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
            $view->setParam('redisSlaveId', $slaveId);
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
        $slaves = [];
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
            // got slaves
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

                        $slaves[$index] = $dbStats;
                    }
                }
            }
        }

        $igbinaryPresent = \is_callable('igbinary_serialize') && \is_callable('igbinary_unserialize');
        $data['serializer'] = empty($config['config']['serializer']) ? ($igbinaryPresent ? 'igbinary' : 'php') : $config['config']['serializer'];
        $data['slaves'] = $slaves;
        $data['db'] = $db;
        $data['db_default'] = $database;
        $data['lua'] = $useLua;
        $data['phpredis'] = phpversion('redis');
        $data['HasIOStats'] = isset($data['instantaneous_input_kbps']) && isset($data['instantaneous_output_kbps']);

        return $data;
    }
}

<?php

namespace SV\RedisCache\XF\Repository;


class Counters extends XFCP_Counters
{
    public function getForumStatisticsCacheData()
    {
        $cache = parent::getForumStatisticsCacheData();
        unset($cache['latestUser']['secret_key']);
        return $cache;
    }
}
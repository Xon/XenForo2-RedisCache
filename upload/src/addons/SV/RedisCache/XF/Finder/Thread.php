<?php

namespace SV\RedisCache\XF\Finder;


use SV\RedisCache\Globals;

class Thread extends XFCP_Thread
{
    /**
     * @return int
     */
    public function total()
    {
        if (Globals::$threadFinder && Globals::$cacheForum && $cache = \XF::app()->cache())
        {
            $forum = Globals::$cacheForum;
            $finder = Globals::$threadFinder;
            Globals::$threadFinder = null;
            Globals::$cacheForum = null;

            $conditions = $finder->conditions;
            \sort($conditions);
            $joins = $finder->joins;
            foreach ($joins as $key => &$join)
            {
                if (!$join['fundamental'] && !$join['exists'])
                {
                    unset($joins[$key]);
                }
                $join = \array_filter($join);
            }
            \ksort($joins);
            $key = 'forum_' . $forum->node_id . '_threadcount_' . \md5(\serialize($conditions) . \serialize($joins) . \serialize($finder->order));

            /** @var int|bool $total */
            $total = $cache->fetch($key);
            if ($total !== false)
            {
                return $total;
            }
            $total = $finder->total();

            $options = \XF::options();
            $longExpiry = (int)($options->sv_threadcountcache_long ?? 0);
            $shortExpiry = (int)($options->sv_threadcountcache_short ?? 0);
            $shortExpiryThreshold = (int)($options->sv_threadcount_short ?? 0) * (int)($options->discussionsPerPage ?? 0);
            $expiry = $total <= $shortExpiryThreshold ? $shortExpiry : $longExpiry;

            if ($expiry > 0)
            {
                $cache->save($key, $total, $expiry);
            }

            return $total;
        }

        return parent::total();
    }
}
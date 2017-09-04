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
        if (Globals::$cacheThreadListFinder && Globals::$cacheForumId && $cache = \XF::app()->cache())
        {
            $cacheThreadListFinder = Globals::$cacheThreadListFinder;
            Globals::$cacheThreadListFinder = null;

            /** @var Thread $newFinder */
            $newFinder = $cacheThreadListFinder();

            $conditions = $newFinder->conditions;
            sort($conditions);
            $key = 'forum_' . Globals::$cacheForumId . '_threadcount_' . md5(serialize($conditions));

            /** @var int|bool $total */
            $total = $cache->fetch($key);
            if ($total !== false)
            {
                return $total;
            }
            $total = $newFinder->total();

            $options = \XF::options();
            $longExpiry = intval($options->sv_threadcountcache_short);
            $shortExpiry = intval($options->sv_threadcountcache_long);
            $shortExpiryThreshold = $shortExpiry * intval($options->discussionsPerPage);
            $expiry = $total <= $shortExpiryThreshold ? $shortExpiry : $longExpiry;

            $cache->save($key, $total, $expiry);

            return $total;
        }

        return parent::total();
    }
}
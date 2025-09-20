<?php

namespace SV\RedisCache\XF\Pub\Controller;

use SV\RedisCache\XF\Finder\Thread as ExtendedThreadFinder;
use XF\Finder\Thread as ThreadFinder;

class Forum extends XFCP_Forum
{
    /** @noinspection PhpCastIsUnnecessaryInspection */
    protected function applyDateLimitFilters(\XF\Entity\Forum $forum, ThreadFinder $threadFinder, array $filters)
    {
        parent::applyDateLimitFilters($forum, $threadFinder, $filters);

        $threadCountCaching = (bool)(\XF::options()->sv_threadcount_caching ?? false);
        if ($threadCountCaching && $this->app()->cache() !== null)
        {
            /** @var ExtendedThreadFinder $threadFinder */
            $threadFinder->cacheTotals(true, $forum);
            // patch 'last_post_date >= ?' condition so caching can work on more than 1 second granularity
            $options = \XF::options();
            $longExpiry = (int)($options->sv_threadcountcache_long ?? 0);
            $shortExpiry = (int)($options->sv_threadcountcache_short ?? 0);
            $minimumRounding = min($shortExpiry, $longExpiry);
            $threadFinder->patchTimeConditionForCaching('last_post_date', $minimumRounding);
        }
    }
}

<?php

namespace SV\RedisCache\XF\Pub\Controller;

use SV\RedisCache\XF\Finder\Thread as ExtendedThreadFinder;
use XF\Finder\Thread as ThreadFinder;

class Forum extends XFCP_Forum
{
    protected function applyDateLimitFilters(\XF\Entity\Forum $forum, ThreadFinder $threadFinder, array $filters)
    {
        parent::applyDateLimitFilters($forum, $threadFinder, $filters);

        $threadCountCaching = (bool)(\XF::options()->sv_threadcount_caching ?? false);
        if ($threadCountCaching && $this->app()->cache() !== null)
        {
            /** @var ExtendedThreadFinder $threadFinder */
            $threadFinder->cacheTotals(true, $forum);
        }
    }
}

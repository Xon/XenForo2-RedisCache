<?php

namespace SV\RedisCache\XF\Pub\Controller;

use SV\RedisCache\Globals;

class Forum extends XFCP_Forum
{
    protected function applyDateLimitFilters(\XF\Entity\Forum $forum, \XF\Finder\Thread $threadFinder, array $filters)
    {
        parent::applyDateLimitFilters($forum, $threadFinder, $filters);

        $threadCountCaching = \XF::options()->sv_threadcount_caching ?? false;
        if ($threadCountCaching && $this->app()->cache())
        {
            Globals::$cacheForum = $forum;
            Globals::$threadFinder = clone $threadFinder;
        }
    }
}

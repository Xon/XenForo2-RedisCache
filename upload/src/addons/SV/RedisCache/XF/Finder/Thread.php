<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF\Finder;

use SV\RedisCache\Finder\CachableFinderTotalTrait;
use XF\Entity\Forum as ForumEntity;

class Thread extends XFCP_Thread
{
    use CachableFinderTotalTrait;

    /**
     * @return int
     * @noinspection PhpCastIsUnnecessaryInspection
     */
    public function total()
    {
        if ($this->svCacheTotals)
        {
            /** @var ForumEntity $forum */
            $forum = $this->svCacheExtra ?? null;
            $nodeId = $forum->node_id ?? 0;

            $options = \XF::options();
            $longExpiry = (int)($options->sv_threadcountcache_long ?? 0);
            $shortExpiry = (int)($options->sv_threadcountcache_short ?? 0);
            $shortExpiryThreshold = (int)($options->sv_threadcount_short ?? 0) * (int)($options->discussionsPerPage ?? 0);

            $total = $this->cachableTotal('forum_' . $nodeId. '_threadcount_', $longExpiry, $shortExpiry, $shortExpiryThreshold);
            if ($total !== null)
            {
                return $total;
            }
        }

        return parent::total();
    }
}
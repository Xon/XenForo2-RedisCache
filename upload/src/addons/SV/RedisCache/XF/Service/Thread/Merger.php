<?php

namespace SV\RedisCache\XF\Service\Thread;

use SV\RedisCache\Repository\Redis;
use XF\Entity\Thread;

/**
 * Extends \XF\Service\Thread\Merger
 */
class Merger extends XFCP_Merger
{
    public function merge($sourceThreadsRaw)
    {
        $ret = parent::merge($sourceThreadsRaw);
        if ($ret)
        {
            if ($sourceThreadsRaw instanceof \XF\Mvc\Entity\AbstractCollection)
            {
                $sourceThreadsRaw = $sourceThreadsRaw->toArray();
            }
            else if ($sourceThreadsRaw instanceof Thread)
            {
                $sourceThreadsRaw = [$sourceThreadsRaw];
            }
            /** @var Thread $sourceThread */
            foreach ($sourceThreadsRaw AS $sourceThread)
            {
                Redis::instance()->purgeThreadCountByForumDeferred($sourceThread->node_id);
            }
        }

        return $ret;
    }
}
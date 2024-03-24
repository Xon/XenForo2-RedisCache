<?php

namespace SV\RedisCache\XF\Service\Thread;

use SV\RedisCache\Repository\Redis as RedisRepo;
use XF\Entity\Thread;
use XF\Mvc\Entity\AbstractCollection;

/**
 * Extends \XF\Service\Thread\Merger
 */
class Merger extends XFCP_Merger
{
    /** @noinspection PhpMissingReturnTypeInspection */
    public function merge($sourceThreadsRaw)
    {
        $ret = parent::merge($sourceThreadsRaw);
        if ($ret)
        {
            if ($sourceThreadsRaw instanceof AbstractCollection)
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
                RedisRepo::get()->purgeThreadCountByForumDeferred($sourceThread->node_id);
            }
        }

        return $ret;
    }
}
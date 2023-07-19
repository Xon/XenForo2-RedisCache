<?php

namespace SV\RedisCache\XF\Service\Thread;

use SV\RedisCache\Repository\Redis;

/**
 * Extends \XF\Service\Thread\Mover
 */
class Mover extends XFCP_Mover
{
    public function move(\XF\Entity\Forum $forum)
    {
        $ret = parent::move($forum);
        if ($ret)
        {
            Redis::instance()->purgeThreadCountByForumDeferred($forum->node_id);
        }

        return $ret;
    }
}
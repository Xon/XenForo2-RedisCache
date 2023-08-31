<?php

namespace SV\RedisCache\XF\Service\Thread;

use SV\RedisCache\Repository\Redis;
use XF\Entity\Forum as ForumEntity;

/**
 * Extends \XF\Service\Thread\Mover
 */
class Mover extends XFCP_Mover
{
    /** @noinspection PhpMissingReturnTypeInspection */
    public function move(ForumEntity $forum)
    {
        $ret = parent::move($forum);
        if ($ret)
        {
            Redis::instance()->purgeThreadCountByForumDeferred($forum->node_id);
        }

        return $ret;
    }
}
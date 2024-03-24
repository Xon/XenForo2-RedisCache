<?php

namespace SV\RedisCache\XF\Service\Thread;

use SV\RedisCache\Repository\Redis as RedisRepo;
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
            RedisRepo::get()->purgeThreadCountByForumDeferred($forum->node_id);
        }

        return $ret;
    }
}
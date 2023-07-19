<?php

namespace SV\RedisCache\XF\Job;



use SV\RedisCache\Repository\Redis;

/**
 * Extends \XF\Job\ThreadAction
 */
class ThreadAction extends XFCP_ThreadAction
{
    protected function applyInternalThreadChange(\XF\Entity\Thread $thread)
    {
        parent::applyInternalThreadChange($thread);

        if ($thread->isChanged(['node_id', 'discussion_state']))
        {
            Redis::instance()->purgeThreadCountByForumDeferred($thread->node_id);
        }
    }
}
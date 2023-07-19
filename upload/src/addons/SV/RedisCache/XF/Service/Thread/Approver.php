<?php

namespace SV\RedisCache\XF\Service\Thread;

use SV\RedisCache\Repository\Redis;

/**
 * Extends \XF\Service\Thread\Approver
 */
class Approver extends XFCP_Approver
{
    protected function onApprove()
    {
        parent::onApprove();
        Redis::instance()->purgeThreadCountByForumDeferred($this->thread->node_id);
    }
}
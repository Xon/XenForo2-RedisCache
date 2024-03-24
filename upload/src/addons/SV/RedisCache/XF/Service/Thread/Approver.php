<?php

namespace SV\RedisCache\XF\Service\Thread;

use SV\RedisCache\Repository\Redis as RedisRepo;

/**
 * Extends \XF\Service\Thread\Approver
 */
class Approver extends XFCP_Approver
{
    protected function onApprove()
    {
        parent::onApprove();
        RedisRepo::get()->purgeThreadCountByForumDeferred($this->thread->node_id);
    }
}
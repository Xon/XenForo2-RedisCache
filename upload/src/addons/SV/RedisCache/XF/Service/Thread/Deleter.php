<?php

namespace SV\RedisCache\XF\Service\Thread;

use SV\RedisCache\Repository\Redis;

/**
 * Extends \XF\Service\Thread\Deleter
 */
class Deleter extends XFCP_Deleter
{
    /** @noinspection PhpMissingReturnTypeInspection */
    public function delete($type, $reason = '')
    {
        $ret = parent::delete($type, $reason);
        if ($ret)
        {
            Redis::instance()->purgeThreadCountByForumDeferred($this->thread->node_id);
        }
        return $ret;
    }
}
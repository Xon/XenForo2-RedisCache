<?php

namespace SV\RedisCache\XF\Service\Thread;

use SV\RedisCache\Repository\Redis as RedisRepo;

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
            RedisRepo::get()->purgeThreadCountByForumDeferred($this->thread->node_id);
        }
        return $ret;
    }
}
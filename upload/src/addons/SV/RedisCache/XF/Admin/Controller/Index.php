<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF\Admin\Controller;

use SV\RedisCache\Repository\Redis as RedisRepo;
use XF\Mvc\Reply\View as ViewReply;

class Index extends XFCP_Index
{
    public function actionIndex()
    {
        $reply = parent::actionIndex();

        if ($reply instanceof ViewReply)
        {
            $redisRepo = RedisRepo::get();
            if ($redisRepo->canViewServerInfo())
            {
                $redisRepo->insertRedisInfoParams($reply);
            }
        }

        return $reply;
    }
}

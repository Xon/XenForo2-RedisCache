<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF\Admin\Controller;

use SV\RedisCache\Repository\Redis;

class Index extends XFCP_Index
{
    public function actionIndex()
    {
        $reply = parent::actionIndex();

        Redis::instance()->insertRedisInfoParams($reply);

        return $reply;
    }
}

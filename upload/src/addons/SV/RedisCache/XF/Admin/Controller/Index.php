<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF\Admin\Controller;

use SV\RedisCache\Repository\Redis as RedisRepo;

class Index extends XFCP_Index
{
    public function actionIndex()
    {
        $reply = parent::actionIndex();

        RedisRepo::get()->insertRedisInfoParams($reply);

        return $reply;
    }
}

<?php

namespace SV\RedisCache\Admin\Controller;

use SV\RedisCache\Repository\Redis as Redis;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class Index extends AbstractController
{
    public function actionIndex(ParameterBag $params): AbstractReply
    {
        $context = $params->get('context') ?: '';
        $replicaId = $params->get('replica_id');

        $view = $this->view('SV\RedisCache:Index\Index', 'svRedisInfo', []);

        Redis::instance()->insertRedisInfoParams($view, $context, $replicaId);

        return $view;
    }
}

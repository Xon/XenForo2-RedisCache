<?php

namespace SV\RedisCache\XF\Admin\Controller;

class Index extends XFCP_Index {
	public function actionIndex()
	{
		$reply = parent::actionIndex();

		\SV\RedisCache\Repository\Redis::instance()->insertRedisInfoParams($reply, null);

		return $reply;
	}
}
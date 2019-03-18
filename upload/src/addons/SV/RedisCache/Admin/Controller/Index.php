<?php

namespace SV\RedisCache\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class Index extends AbstractController  {

	public function actionIndex(ParameterBag $params)
	{
		$slaveId = $params->get('slave_id');

		$view = $this->view('SV\RedisCache:Index\Index', 'SV_Redis_info', []);

		\SV\RedisCache\Repository\Redis::instance()->insertRedisInfoParams($view, $slaveId);

		return $view;
	}


}

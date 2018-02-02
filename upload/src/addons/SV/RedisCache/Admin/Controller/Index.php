<?php

namespace SV\RedisCache\Admin\Controller;

use Credis_Client;
use SV\RedisCache\Redis;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\Reply\View;
use XF\Mvc\ParameterBag;

class Index extends AbstractController  {

	public function actionIndex(ParameterBag $params)
	{
		$slaveId = $params->get('slave_id');

		$view = $this->view('SV\RedisCache:Index\Index', 'SV_Redis_info_Wrapper', []);

		if ($cache = \XF::app()->cache())
		{
			if ($cache instanceof Redis &&
                $credis = $cache->getCredis(false))
			{
				$useLua = $cache->useLua();

				self::addRedisInfo($view, $credis->info(), $useLua);
				$redisInfo = $view->getParam('redis');
				$slaves = $redisInfo['slaves'];

				$config = \XF::app()->config();
				$database = empty($config['cache']['backendOptions']['database']) ? 0 : (int)$config['cache']['backendOptions']['database'];
				$password = empty($config['cache']['backendOptions']['password']) ? null : $config['cache']['backendOptions']['password'];
				$timeout = empty($config['cache']['backendOptions']['timeout']) ? null : $config['cache']['backendOptions']['timeout'];
				$persistent = empty($config['cache']['backendOptions']['persistent']) ? null : $config['cache']['backendOptions']['persistent'];
				$forceStandalone = empty($config['cache']['backendOptions']['force_standalone']) ? null : $config['cache']['backendOptions']['force_standalone'];

				if (isset($slaves[$slaveId]))
				{
					$slaveDetails = $slaves[$slaveId];
					// query the slave for stats
					$slaveClient = new Credis_Client($slaveDetails['ip'], $slaveDetails['port'], $timeout, $persistent, $database, $password);
					if ($forceStandalone)
					{
						$slaveClient->forceStandalone();
					}
					self::addRedisInfo($view, $slaveClient->info(), $useLua);

					$paramItem = ['redis'=>['slaveId'=>$slaveId]];
					$view->setParams($paramItem, true);

				}

			}
		}

		return $view;
	}

	private static function addRedisInfo(View $response, array $data, $useLua = true)
	{
		$database = 0;
		$slaves = array();
		$db = array();

		if (!empty($data))
		{
			$config = \XF::app()->config();
			if (!empty($config['cache']['backendOptions']['database']))
			{
				$database = (int)$config['cache']['backendOptions']['database'];
			}

			foreach ($data as $key => &$value)
			{
				if (preg_match('/^db(\d+)$/i', $key, $matches))
				{
					$index = $matches[1];
					unset($data[$key]);
					$list = explode(',', $value);
					$dbStats = array();
					foreach ($list as $item)
					{
						$parts = explode('=', $item);
						$dbStats[$parts[0]] = $parts[1];
					}

					$db[$index] = $dbStats;
				}
			}
			// got slaves
			if (isset($data['connected_slaves']) && isset($data['master_repl_offset']))
			{
				foreach ($data as $key => &$value)
				{
					if (preg_match('/^slave(\d+)$/i', $key, $matches))
					{
						$index = $matches[1];
						unset($data[$key]);
						$list = explode(',', $value);
						$dbStats = array();
						foreach ($list as $item)
						{
							$parts = explode('=', $item);
							$dbStats[$parts[0]] = $parts[1];
						}

						$slaves[$index] = $dbStats;
					}
				}
			}
		}

		$data['slaves'] = $slaves;
		$data['db'] = $db;
		$data['db_default'] = $database;
		$data['lua'] = $useLua;
		$data['phpredis'] = phpversion('redis');
		$data['HasIOStats'] = isset($data['instantaneous_input_kbps']) && isset($data['instantaneous_output_kbps']);
		$response->setParam('redis', $data);
	}
}

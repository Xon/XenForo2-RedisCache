<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF;

use SV\RedisCache\Redis;

/**
 * Extends \XF\Debugger
 */
class Debugger extends XFCP_Debugger
{
    public function getDebugPageWrapperHtml($debugHtml)
    {
        $app = $this->app;
        $pageTime = \microtime(true) - $app['time.granular'];

        $mainConfig = \XF::app()->config()['cache'];
        $contexts = [];
        $contexts[''] = $mainConfig;
        if (isset($mainConfig['context']))
        {
            $contexts = $contexts + $mainConfig['context'];
        }

        $redisSections = '';
        $time = 0;
        $count = 0;
        foreach ($contexts as $contextLabel => $config)
        {
            $cache = \XF::app()->cache($contextLabel, false);
            if ($cache instanceof Redis)
            {
                $statsHtml = "<table>\n";

                foreach ($cache->getRedisStats() as $statName => $statValue)
                {
                    if (\preg_match('#^.*\.time$#', $statName))
                    {
                        $time += $statValue;
                    }
                    else if (!\preg_match('#^bytes|^time_#', $statName))
                    {
                        $count += $statValue;
                    }
                    $statsHtml .= '<tr><td>' . \htmlspecialchars($statName) . '</td><td>' . \htmlspecialchars($statValue) . "</td></tr>\n";
                }

                $statsHtml .= "</table>\n";


                $redisSections .= "\n<h3>" . \htmlspecialchars($contextLabel ?: 'main') . "</h3>\n" . $statsHtml;
            }
        }

        //  (13, time: 0.0066s, 0.1%)
        if ($redisSections)
        {
            $dbPercent = ($time / $pageTime) * 100;
            $time = \number_format($time, 4);
            $percentage = \number_format($dbPercent, 1);
            $debugHtml .= "\n<h2>Redis Connection stats ({$count}, time: {$time}s, {$percentage}%)</h2>\n" . $redisSections;
        }


        return parent::getDebugPageWrapperHtml($debugHtml);
    }
}
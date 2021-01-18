<?php
/**
 * @noinspection PhpMissingParamTypeInspection
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF\Repository;

use SV\RedisCache\Redis;

/**
 * Extends \XF\Repository\Style
 */
class Style extends XFCP_Style
{
    public function rebuildStyleCache()
    {
        $cache = parent::rebuildStyleCache();
        $this->styleCachePurge();

        return $cache;
    }

    public function styleCachePurge()
    {
        $this->_clearCache("xfCssCache_");
        $this->_clearCache("xfSvgCache_");
    }

    /**
     * @param string   $pattern
     * @param int|null $styleId
     * @noinspection PhpUnusedParameterInspection
     */
    protected function _clearCache($pattern, $styleId = null)
    {
        $cache = \XF::app()->cache();
        if (($cache instanceof Redis) && ($credis = $cache->getCredis(false)))
        {
            $pattern = $cache->getNamespacedId($pattern) . "*";
            $expiry = 2 * 60;
            // indicate to the redis instance would like to process X items at a time.
            $count = 100;
            // prevent looping forever
            $loopGuard = 10000;
            // find indexes matching the pattern
            $cursor = null;
            do
            {
                $keys = $credis->scan($cursor, $pattern, $count);
                $loopGuard--;
                if ($keys === false)
                {
                    break;
                }

                // adjust TTL them, use pipe-lining
                $credis->pipeline();
                foreach ($keys as $key)
                {
                    if ($key)
                    {
                        $credis->expire($key, $expiry);
                    }
                }
                $credis->exec();
            }
            while ($loopGuard > 0 && !empty($cursor));
        }
    }
}
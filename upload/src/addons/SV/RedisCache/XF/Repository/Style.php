<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF\Repository;

use SV\RedisCache\Job\ExpireRedisCacheByPattern;
use SV\RedisCache\Repository\Redis as RedisRepo;

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
        $this->_svClearCache('xfCssCache_');
        $this->_svClearCache('xfSvgCache_');
    }

    /**
     * @param string   $pattern
     * @param int|null $styleId
     * @noinspection PhpUnusedParameterInspection
     */
    protected function _svClearCache(string $pattern, int $styleId = null)
    {
        ExpireRedisCacheByPattern::enqueue($pattern, $pattern, 120, 'css');
    }
}
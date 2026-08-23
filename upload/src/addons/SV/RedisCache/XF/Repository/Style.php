<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF\Repository;

use SV\RedisCache\Repository\Redis as RedisRepo;

/**
 * @Extends \XF\Repository\Style
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
        RedisRepo::get()->clearCssCache();
    }

    /**
     * @deprecated
     * @param string   $pattern
     * @param int|null $styleId
     */
    protected function _svClearCache(string $pattern, ?int $styleId = null)
    {
        RedisRepo::get()->clearCssCacheByPattern($pattern, $styleId);
    }
}
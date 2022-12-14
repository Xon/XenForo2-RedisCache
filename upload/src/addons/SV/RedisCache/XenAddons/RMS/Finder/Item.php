<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XenAddons\RMS\Finder;

use SV\RedisCache\Finder\CachableFinderTotalTrait;

/**
 * Extends \XenAddons\RMS\Finder\Item
 */
class Item extends XFCP_Item
{
    use CachableFinderTotalTrait;

    /**
     * @return int
     */
    public function total()
    {
        if ($this->svCacheTotals)
        {
            $options = \XF::options();
            $longExpiry = (int)($options->svRmsItemCountLongExpiry ?? 0);
            $shortExpiry = (int)($options->svRmsItemCountShortExpiry ?? 0);
            $shortExpiryThreshold = (int)($options->svRmsItemCountSmallThreshold ?? 0);

            $total = $this->cachableTotal('rms_category_count_', $longExpiry, $shortExpiry, $shortExpiryThreshold);
            if ($total !== null)
            {
                return $total;
            }
        }

        return parent::total();
    }
}
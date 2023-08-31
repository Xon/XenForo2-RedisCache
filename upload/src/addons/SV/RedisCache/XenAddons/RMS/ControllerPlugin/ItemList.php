<?php

namespace SV\RedisCache\XenAddons\RMS\ControllerPlugin;

use XenAddons\RMS\Finder\Item as ItemFinder;
use function is_callable;

/**
 * Extends \XenAddons\RMS\ControllerPlugin\ItemList
 */
class ItemList extends XFCP_ItemList
{
    public function applyItemFilters(ItemFinder $itemFinder, array $filters)
    {
        if ((\XF::options()->svRmsItemCountCaching ?? false) && is_callable([$itemFinder, 'cacheTotals']))
        {
            $itemFinder->cacheTotals();
        }

        parent::applyItemFilters($itemFinder, $filters);
    }
}
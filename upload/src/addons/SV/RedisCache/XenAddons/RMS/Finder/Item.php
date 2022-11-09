<?php

namespace SV\RedisCache\XenAddons\RMS\Finder;

/**
 * Extends \XenAddons\RMS\Finder\Item
 */
class Item extends XFCP_Item
{
    protected $svCacheTotals = false;

    public function cacheTotals()
    {
        $this->svCacheTotals = true;
    }

    /**
     * @return int
     * @noinspection DuplicatedCode
     */
    public function total()
    {
        if ($this->svCacheTotals && $cache = \XF::app()->cache())
        {
            $conditions = $this->conditions;
            \sort($conditions);
            $joins = $this->joins;
            foreach ($joins as $key => &$join)
            {
                if (!$join['fundamental'] && !$join['exists'])
                {
                    unset($joins[$key]);
                }
                $join = \array_filter($join);
            }
            \ksort($joins);
            $key = 'rms_category_count_' . \md5(\serialize($conditions) . \serialize($joins) . \serialize($this->order));

            /** @var int|bool $total */
            $total = $cache->fetch($key);
            if ($total !== false)
            {
                return $total;
            }
            $total = parent::total();

            $options = \XF::options();
            $longExpiry = (int)($options->svRmsItemCountLongExpiry ?? 0);
            $shortExpiry = (int)($options->svRmsItemCountShortExpiry ?? 0);
            $shortExpiryThreshold = (int)($options->svRmsItemCountSmallThreshold ?? 0);
            $expiry = $total <= $shortExpiryThreshold ? $shortExpiry : $longExpiry;

            if ($expiry > 0)
            {
                $cache->save($key, $total, $expiry);
            }

            return $total;
        }

        return parent::total();
    }
}
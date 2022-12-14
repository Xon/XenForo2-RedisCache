<?php

namespace SV\RedisCache\Finder;

trait CachableFinderTotalTrait
{
    /** @var bool */
    protected $svCacheTotals = false;
    /** @var null|mixed */
    protected $svCacheExtra = null;

    /**
     * @return $this
     * @noinspection PhpMissingReturnTypeInspection
     */
    public function cacheTotals(bool $cacheTotals = true, $extra = null)
    {
        $this->svCacheTotals = $cacheTotals;
        $this->svCacheExtra = $extra;

        return $this;
    }

    protected function cachableTotal(string $prefix, int $longExpiry, int $shortExpiry, int $shortExpiryThreshold): ?int
    {
        $cache = \XF::app()->cache();
        if ($cache === null)
        {
            return null;
        }

        $conditions = $this->conditions;
        \sort($conditions);
        $joins = $this->joins;
        foreach ($joins as $key => &$join)
        {
            if (!$join['fundamental'] && !$join['exists'])
            {
                unset($joins[$key]);
                continue;
            }
            $join = \array_filter($join);
        }
        \ksort($joins);
        $key = $prefix . \md5(\serialize($conditions) . \serialize($joins) . \serialize($this->order));

        /** @var int|bool $total */
        $total = $cache->fetch($key);
        if ($total !== false)
        {
            return $total;
        }
        $this->svCacheTotals = false;
        $total = $this->total();

        $expiry = $total <= $shortExpiryThreshold ? $shortExpiry : $longExpiry;
        if ($expiry > 0)
        {
            $cache->save($key, $total, $expiry);
        }

        return $total;
    }
}
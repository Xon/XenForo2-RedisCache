<?php

namespace SV\RedisCache\Finder;

use function array_filter;
use function is_object;
use function ksort;
use function md5;
use function preg_match;
use function preg_quote;
use function serialize;
use function sort;

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

    public function patchTimeConditionForCaching(string $column, int $expiryToRound)
    {
        $sqlColumn = $this->columnSqlName($column);
        $regex = '/'.preg_quote($sqlColumn).' >= (\d+)/i';
        foreach ($this->conditions as &$condition)
        {
            if (is_string($condition) && preg_match($regex, $condition, $match))
            {
                $timestamp = (int)$match[1];
                if ($timestamp === 0)
                {
                    continue;
                }

                $timestamp = $timestamp - ($timestamp % $expiryToRound);

                $condition = $sqlColumn . ' >= '. $timestamp;
                break;
            }
        }
    }

    protected function cachableTotal(string $prefix, int $longExpiry, int $shortExpiry, int $shortExpiryThreshold): ?int
    {
        $cache = \XF::app()->cache();
        if ($cache === null)
        {
            return null;
        }

        $conditions = $this->conditions;
        sort($conditions);
        $joins = $this->joins;
        foreach ($joins as $key => &$join)
        {
            if (!$join['fundamental'] && !$join['exists'])
            {
                unset($joins[$key]);
                continue;
            }
            // exclude objects (ie $join['structure']) as it can contain arbitrary unserializable data
            $join = array_filter($join, function ($v) {
                return $v && !is_object($v);
            });
        }
        ksort($joins);
        $key = $prefix . hash('md5', serialize($conditions) . serialize($joins) . serialize($this->order));

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
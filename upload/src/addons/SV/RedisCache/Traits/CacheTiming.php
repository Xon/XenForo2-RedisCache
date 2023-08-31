<?php

namespace SV\RedisCache\Traits;

use function function_exists;
use function hrtime;
use function microtime;

trait CacheTiming
{

    protected $stats = [
        'gets'               => 0,
        'gets.time'          => 0,
        'sets'               => 0,
        'sets.time'          => 0,
        'deletes'            => 0,
        'deletes.time'       => 0,
        'flushes'            => 0,
        'flushes.time'       => 0,
        'bytes_sent'         => 0,
        'bytes_received'     => 0,
        'time_compression'   => 0,
        'time_decompression' => 0,
        'time_encoding'      => 0,
        'time_decoding'      => 0,
    ];

    /**
     * @var bool
     */
    protected $debug = false;

    /** @var \Closure|null */
    protected $redisQueryForStat = null;
    /** @var \Closure|null */
    protected $timerForStat = null;

    protected function redisQueryForStat($stat, \Closure $callback)
    {
        $this->stats[$stat]++;

        return $callback();
    }

    protected function redisQueryForStatDebug($stat, \Closure $callback)
    {
        $this->stats[$stat]++;
        $startTime = microtime(true);
        try
        {
            return $callback();
        }
        finally
        {
            $endTime = microtime(true);

            $this->stats[$stat . '.time'] += ($endTime - $startTime);
        }
    }

    /**
     * @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection
     */
    protected function redisQueryForStatDebugPhp73($stat, \Closure $callback)
    {
        $this->stats[$stat]++;

        /** @var float $startTime */
        $startTime = hrtime(true);
        try
        {
            return $callback();
        }
        finally
        {
            /** @var float $endTime */
            $endTime = hrtime(true);

            $this->stats[$stat . '.time'] += ($endTime - $startTime) / 1000000000;
        }
    }

    /** @noinspection PhpUnusedParameterInspection */
    protected function timerForStat($stat, \Closure $callback)
    {
        return $callback();
    }

    protected function timerForStatDebug($stat, \Closure $callback)
    {
        $startTime = microtime(true);
        try
        {
            return $callback();
        }
        finally
        {
            $endTime = microtime(true);

            $this->stats[$stat] += ($endTime - $startTime);
        }
    }

    /**
     * @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection
     */
    protected function timerForStatDebugPhp73($stat, \Closure $callback)
    {
        /** @var float $startTime */
        $startTime = hrtime(true);
        try
        {
            return $callback();
        }
        finally
        {
            /** @var float $endTime */
            $endTime = hrtime(true);

            $this->stats[$stat] += ($endTime - $startTime) / 1000000000;
        }
    }

    protected function setupTimers(bool $debug)
    {
        $this->debug = $debug;
        if ($this->debug)
        {
            if (function_exists('\hrtime'))
            {
                $this->timerForStat = [$this, 'timerForStatDebugPhp73'];
                $this->redisQueryForStat = [$this, 'redisQueryForStatDebugPhp73'];
            }
            else
            {
                $this->timerForStat = [$this, 'timerForStatDebug'];
                $this->redisQueryForStat = [$this, 'redisQueryForStatDebug'];
            }
        }
        else
        {
            $this->timerForStat = [$this, 'timerForStat'];
            $this->redisQueryForStat = [$this, 'redisQueryForStat'];
        }

        $this->redisQueryForStat = \Closure::fromCallable($this->redisQueryForStat);
        $this->timerForStat = \Closure::fromCallable($this->timerForStat);
    }

    public function getRedisStats(): array
    {
        return $this->stats;
    }
}
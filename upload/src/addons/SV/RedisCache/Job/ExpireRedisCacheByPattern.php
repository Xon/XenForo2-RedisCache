<?php

namespace SV\RedisCache\Job;

use SV\RedisCache\Repository\Redis as RedisRepo;
use XF\Job\AbstractJob;
use XF\Job\JobResult;
use function microtime;

class ExpireRedisCacheByPattern extends AbstractJob
{
    public static function enqueue(string $key, string $patternPrefix, int $expiry, string $context = ''): ?int
    {
        if ($expiry <= 0)
        {
            return PurgeRedisCacheByPattern::enqueue($key, $patternPrefix, $context);
        }

        return \XF::app()->jobManager()->enqueueUnique(
            $key, ExpireRedisCacheByPattern::class, [
            'pattern' => $patternPrefix,
            'expiry'  => $expiry,
            'context' => $context,
        ], false);
    }


    protected $defaultData = [
        'context' => '',
        'pattern' => null,
        'steps'   => 0,
        'cursor'  => null, // null - start new, 0 - stop, otherwise it is a blob returned from redis
        'batch'   => 1000,
        'expiry'  => 0,
    ];

    /**
     * @param float $maxRunTime
     * @return JobResult
     */
    public function run($maxRunTime): JobResult
    {
        if ($this->data['pattern'] === null)
        {
            return $this->complete();
        }

        $startTime = microtime(true);
        $cache = \XF::app()->cache($this->data['context'] ?? '');

        /** @var string|int|null $cursor */
        $cursor = $this->data['cursor'];
        $steps = RedisRepo::get()->expireCacheByPattern($this->data['expiry'], $this->data['pattern'], $cursor, $maxRunTime, $this->data['batch'], $cache);
        if (!$cursor)
        {
            return $this->complete();
        }

        $this->data['steps'] += $steps;
        $this->data['cursor'] = $cursor;
        $this->data['batch'] = $this->calculateOptimalBatch($this->data['batch'], $steps, $startTime, $maxRunTime, 10000);

        return $this->resume();
    }

    public function getStatusMessage(): string
    {
        return '';
    }

    public function canCancel(): bool
    {
        return false;
    }

    public function canTriggerByChoice(): bool
    {
        return false;
    }
}
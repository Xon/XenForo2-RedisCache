<?php
/**
 * @noinspection DuplicatedCode
 */

namespace SV\RedisCache\Job;

use SV\RedisCache\Repository\Redis;
use XF\Job\AbstractJob;
use XF\Job\JobResult;
use function microtime;

class PurgeRedisCacheByPattern extends AbstractJob
{
    protected $defaultData = [
        'pattern' => null,
        'steps'   => 0,
        'cursor'  => null,
        'batch'   => 1000,
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

        /** @var string|null $cursor */
        $cursor = $this->data['cursor'];
        $done = Redis::instance()->purgeCacheByPattern($this->data['pattern'], $cursor, $maxRunTime, $this->data['batch']);
        if (!$cursor)
        {
            return $this->complete();
        }

        $this->data['steps'] += $done;
        $this->data['cursor'] = $cursor;
        $this->data['batch'] = $this->calculateOptimalBatch($this->data['batch'], $done, $startTime, $maxRunTime, 10000);

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
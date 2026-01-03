<?php
/**
 * @noinspection PhpCastIsUnnecessaryInspection
 */

namespace SV\RedisCache\XF\Repository;

use SV\RedisCache\Repository\Redis as RedisRepo;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;
use function array_keys;
use function count;
use function is_array;
use function json_encode;

/**
 * @Extends \XF\Repository\SessionActivity
 */
class SessionActivity extends XFCP_SessionActivity
{
    public function getOnlineCounts($onlineCutOff = null)
    {
        $cache = $this->app()->cache();
        $cacheUsersOnline = (int)(\XF::options()->svCacheUsersOnline ?? 0);

        $cacheKey = null;
        if ($cacheUsersOnline > 0 && $cache !== null)
        {
            $cacheKey = 'onlineCounts';
            $counts = $cache->fetch($cacheKey);
            if (is_array($counts))
            {
                return $counts;
            }
        }

        $counts = parent::getOnlineCounts($onlineCutOff);

        if ($cacheKey !== null)
        {
            $cache->save($cacheKey, $counts, $cacheUsersOnline);
        }

        return $counts;
    }

    /** @noinspection PhpCastIsUnnecessaryInspection */
    public function getOnlineStatsBlockData($forceIncludeVisitor, $userLimit, $staffQuery = false)
    {
        $app = $this->app();
        $em = $app->em();
        $cache = $app->cache();
        $cacheUsersOnline = (int)(\XF::options()->svCacheUsersOnline ?? 0);
        $cacheKey = null;
        if ($cacheUsersOnline > 0 && $cache !== null)
        {
            $keyParts = [$forceIncludeVisitor, $userLimit, $staffQuery];
            // must be pre-user or otherwise the followed user list breaks :(
            $keyParts[] = \XF::visitor()->user_id;
            $cacheKey = 'onlineList.' . $cacheUsersOnline . '.' . hash('md5', json_encode($keyParts));
            $result = $cache->fetch($cacheKey);
            if (is_array($result))
            {
                $userIds = $result['userIds'] ?? [];
                unset($result['userIds']);

                $users = [];
                $userIdsToLoad = [];
                foreach ($userIds as $userId)
                {
                    $user = $em->findCached('XF:User', $userId);
                    if ($user)
                    {
                        $users[$userId] = $user;
                    }
                    else
                    {
                        $userIdsToLoad[$userId] = $userId;
                    }
                }
                unset($userIdsToLoad[0]);

                if (count($userIdsToLoad) !== 0)
                {
                    $loadedUsers = $app->finder('XF:User')
                                       ->where('user_id', $userIdsToLoad)
                                       ->fetch()
                                       ->toArray();
                    if (count($loadedUsers) !== 0)
                    {
                        $toSort = new ArrayCollection($users + $loadedUsers);
                        $users = $toSort->sortByList($userIds)
                                        ->toArray();
                    }
                }

                $result['users'] = $users;

                return $result;
            }
        }

        $onlineStatsBlock = parent::getOnlineStatsBlockData($forceIncludeVisitor, $userLimit, $staffQuery);

        if ($cacheKey !== null)
        {
            $users = $onlineStatsBlock['users'] ?? [];
            if ($users instanceof AbstractCollection)
            {
                $userIds = $users->keys();
            }
            else if (is_array($users))
            {
                $userIds = array_keys($users);
            }
            else
            {
                $userIds = [];
            }
            $onlineStats = [
                'counts'  => $onlineStatsBlock['counts'] ?? [],
                'userIds' => $userIds,
            ];

            $repo = RedisRepo::get();

            foreach ($onlineStats['counts'] as &$value)
            {
                $value = $repo->stringToFloatInt($value);
            }

            $cache->save($cacheKey, $onlineStats, $cacheUsersOnline);
        }

        return $onlineStatsBlock;
    }
}
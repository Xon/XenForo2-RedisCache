<?php

namespace SV\RedisCache\XF\Repository;

use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;

/**
 * Extends \XF\Repository\SessionActivity
 */
class SessionActivity extends XFCP_SessionActivity
{
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
            $cacheKey = 'onlineList.' . $cacheUsersOnline . '.' . \md5(\json_encode($keyParts));
            $result = $cache->fetch($cacheKey);
            if (\is_array($result))
            {
                $userIds = $result['userIds'] ?? [];
                unset($result['userIds']);

                $users = [];
                $userIdsToLoad = [];
                foreach ($userIds as $userId)
                {
                    $user = $em->findCached('XF:User', $userId);
                    if ($user !== null)
                    {
                        $users[$userId] = $user;
                    }
                    else
                    {
                        $userIdsToLoad[$userId] = $userId;
                    }
                }
                if (\count($userIdsToLoad) !== 0)
                {
                    $loadedUsers = $app->finder('XF:User')
                                       ->whereIds($userIdsToLoad)
                                       ->fetch()
                                       ->toArray();
                    if (\count($loadedUsers) !== 0)
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
            else if (\is_array($users))
            {
                $userIds = \array_keys($users);
            }
            else
            {
                $userIds = [];
            }
            $onlineStats = [
                'counts'  => $onlineStatsBlock['counts'] ?? [],
                'userIds' => $userIds,
            ];

            foreach ($onlineStats['counts'] as &$value)
            {
                if (\is_numeric($value))
                {
                    try
                    {
                        /** @noinspection PhpWrongStringConcatenationInspection */
                        $value = \strval(\floatval($value)) + 0;
                    }
                    catch (\Throwable $e)
                    {
                        $value = 0;
                    }
                }
                else
                {
                    $value = 0;
                }
            }

            $cache->save($cacheKey, $onlineStats, $cacheUsersOnline);
        }

        return $onlineStatsBlock;
    }
}
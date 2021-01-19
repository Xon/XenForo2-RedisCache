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
        if ($cacheUsersOnline > 0 && $cache)
        {
            $keyParts = [$forceIncludeVisitor, $userLimit, $staffQuery];
            // must be pre-user or otherwise the followed user list breaks :(
            $keyParts[] = \XF::visitor()->user_id;
            $cacheKey = 'onlineList.' . $cacheUsersOnline . '.' . md5(\json_encode($keyParts));
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
                if ($userIdsToLoad)
                {
                    $loadedUsers = $app->find('XF:User', $userIdsToLoad)->toArray();
                    if ($loadedUsers)
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

        if ($cacheKey)
        {
            $onlineStats = $onlineStatsBlock;

            if (isset($onlineStats['counts']))
            {
                foreach ($onlineStats['counts'] as $key => &$value)
                {
                    if (\is_numeric($value))
                    {
                        /** @noinspection PhpWrongStringConcatenationInspection */
                        $value = strval(floatval($value)) + 0;
                    }
                }
            }

            $users = $onlineStats['users'];
            unset($onlineStats['users']);
            $onlineStats['userIds'] = $users instanceof AbstractCollection
                ? $users->keys()
                : \array_keys($users);

            $cache->save($cacheKey, $onlineStats, $cacheUsersOnline);
        }

        return $onlineStatsBlock;
    }
}
<?php

namespace SV\RedisCache\XF\Pub\Controller;

use SV\RedisCache\Globals;
use XF\Mvc\ParameterBag;
use XF\PermissionCache;

class Forum extends XFCP_Forum
{
    public function actionForum(ParameterBag $params)
    {
        if (\XF::options()->sv_threadcount_caching && $this->app()->cache())
        {
            Globals::$cacheThreadListFinder = function ()
            {
                return $this->setupCacheCountFinder();
            };
        }
        return parent::actionForum($params);
    }

    /** @var \XF\Entity\Forum */
    protected $_cacheForum;

    protected function applyForumFilters(\XF\Entity\Forum $forum, \XF\Finder\Thread $threadFinder, array $filters)
    {
        parent::applyForumFilters($forum, $threadFinder, $filters);
        if (Globals::$cacheThreadListFinder)
        {
            $this->_cacheForum = $forum;
            Globals::$cacheForumId = $forum->node_id;;
        }
    }

    public function getCacheCountFinder()
    {
        $forum = $this->_cacheForum;

        $threadRepo = $this->getThreadRepo();
        $threadList = $threadRepo->findThreadsForForumView($forum);

        $filters = $this->getForumFilterInput($forum);
        $this->applyForumFilters($forum, $threadList, $filters);

        $threadList->where('sticky', 0);

        return $threadList;
    }

    static $RedisCachePermRewriteId = -3748236;

    public function setupCacheCountFinder()
    {
        $forum = $this->_cacheForum;
        /** @var int $forumId */
        $forumId = $forum->node_id;

        $options = \XF::options();
        $visitor = \XF::visitor();
        $user = $visitor;
        if ($options->sv_threadcount_sv_threadcount_moderated)
        {
            $permCache = \XF::app()->PermissionCache();
            // detach & clone to ensure this visitor copy doesn't persist the changes we need to make
            $this->em()->detachEntity($visitor);
            $user = clone $visitor;
            $this->em()->attachEntity($visitor);

            $user->setReadOnly(false);

            // required to wipe the PermissionSet cache which can be populated by getPermissionCombinationId
            $permissions = $permCache->getContentPerms($user->getPermissionCombinationId(), 'node', $forumId);
            $user->reset();

            // rewrite the permission entry, and ensure the user is forced to use it
            $user->setTrusted('user_id', 0);
            $user->setTrusted('user_state', 'valid');
            $permissions['viewModerated'] = false;
            //$permissions['viewDeleted'] = false;
            $permCache->setContentPerms(static::$RedisCachePermRewriteId, 'node', $forumId, $permissions);
            $user->setTrusted('permission_combination_id', static::$RedisCachePermRewriteId);

            $user->setReadOnly(true);

        }
        return \XF::asVisitor($user, function () {
            return $this->getCacheCountFinder();
        });
    }
}

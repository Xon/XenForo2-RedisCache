<?php

namespace SV\RedisCache;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

/**
 * Add-on installation, upgrade, and uninstall routines.
 */
class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function upgrade2010500Step1()
    {
        $cache = \XF::app()->cache();
        if (($cache instanceof Redis) && ($credis = $cache->getCredis(false)))
        {
            $pattern = $cache->getNamespacedId('xfCssCache_'). "*";
            // indicate to the redis instance would like to process X items at a time.
            $count = 100;
            // prevent looping forever
            $loopGuard = 10000;
            // find indexes matching the pattern
            $cursor = null;
            do
            {
                $keys = $credis->scan($cursor, $pattern , $count);
                $loopGuard--;
                if ($keys === false)
                {
                    break;
                }

                foreach ($keys as $key)
                {
                    try
                    {
                        $credis->del($key);
                    }
                    catch(\Exception $e)
                    {

                    }
                }
            }
            while ($loopGuard > 0 && !empty($cursor));
        }
    }

    public function postInstall(array &$stateChanges)
    {
        $this->db()->emptyTable('xf_css_cache');
    }

    public function postUpgrade($previousVersion, array &$stateChanges)
    {
        $this->db()->emptyTable('xf_css_cache');
    }
}


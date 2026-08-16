<?php

namespace SV\RedisCache;

use SV\InstallerAppHelper\InstallAppBootstrap;
use SV\RedisCache\Job\ExpireRedisCacheByPattern;
use SV\RedisCache\Repository\Redis as RedisRepo;
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

    public function uninstallStep1(): void
    {
        $this->db()->emptyTable('xf_css_cache');
        RedisRepo::get()->clearCssCache();
    }

    public function postInstall(array &$stateChanges): void
    {
        parent::postInstall($stateChanges);
        $this->syncActions();
    }

    public function postUpgrade($previousVersion, array &$stateChanges): void
    {
        $previousVersion = (int)$previousVersion;
        parent::postUpgrade($previousVersion, $stateChanges);
        $this->syncActions();
    }

    public function postRebuild(): void
    {
        parent::postRebuild();
        $this->syncActions();
    }

    protected function syncActions(): void
    {
        $this->db()->emptyTable('xf_css_cache');
        RedisRepo::get()->shimAdminNavigation();
        RedisRepo::get()->clearCssCache();
    }

    public function markAsCriticalAddon(): void
    {
        // XF2.1 compatible code, can't use \XF::isAddonActive
        // using SV\StandardLib\Helper::hasDesiredAddOnVersion() would pull in another dependency
        $addOns = \XF::app()->container('addon.cache');
        if (isset($addOns['SV/InstallerAppHelper']))
        {
            InstallAppBootstrap::markAddonCritical('SV/RedisCache');
        }
    }
}


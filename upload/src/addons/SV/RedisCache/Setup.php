<?php

namespace SV\RedisCache;

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
    }
}


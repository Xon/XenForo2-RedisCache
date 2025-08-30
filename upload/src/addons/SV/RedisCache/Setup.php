<?php
/**
 * @noinspection PhpMissingParentCallCommonInspection
 */

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
        $this->db()->emptyTable('xf_css_cache');
        RedisRepo::get()->shimAdminNavigation();
    }

    public function postUpgrade($previousVersion, array &$stateChanges): void
    {
        $this->db()->emptyTable('xf_css_cache');
        RedisRepo::get()->shimAdminNavigation();
    }

    public function postRebuild(): void
    {
        parent::postRebuild();
        RedisRepo::get()->shimAdminNavigation();
    }
}


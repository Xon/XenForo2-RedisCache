<?php
/**
 * @noinspection PhpMissingParentCallCommonInspection
 */

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

    public function postInstall(array &$stateChanges): void
    {
        $this->db()->emptyTable('xf_css_cache');
    }

    public function postUpgrade($previousVersion, array &$stateChanges): void
    {
        $this->db()->emptyTable('xf_css_cache');
    }
}


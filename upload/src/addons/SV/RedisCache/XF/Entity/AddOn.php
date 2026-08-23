<?php

namespace SV\RedisCache\XF\Entity;

/**
 * @extends \XF\Entity\AddOn
 */
class AddOn extends XFCP_AddOn
{
    protected function _preSave()
    {
        // is_processing can result in bad css being cached after updating this add-on
        if ($this->isChanged('is_processing') && $this->is_processing && $this->addon_id === 'SV/RedisCache')
        {
            $this->is_processing = false;
        }

        parent::_preSave();
    }
}
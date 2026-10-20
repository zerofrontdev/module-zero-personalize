<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Fixstacks_AwsPack is gone (Zero_Personalize replaces it): its settings,
 * an encrypted AWS secret among them, and its module row removed.
 */
class RemoveAwsPack implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $setup
    ) {
    }

    public function apply(): self
    {
        $db = $this->setup->getConnection();
        $db->delete($this->setup->getTable('core_config_data'), ['path LIKE ?' => 'fixstacks_awspack/%']);
        $db->delete($this->setup->getTable('setup_module'), ['module = ?' => 'Fixstacks_AwsPack']);
        $db->delete($this->setup->getTable('flag'), ['flag_code LIKE ?' => 'fixstacks_awspack%']);
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}

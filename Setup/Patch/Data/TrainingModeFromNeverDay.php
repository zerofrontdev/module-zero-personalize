<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Zero\Personalize\Model\Config;
use Zero\Personalize\Model\Source\TrainMode;

/**
 * "Train Every: Never" became Training: By hand. A store that had chosen
 * Never gets the new setting, and its day goes back to the default.
 */
class TrainingModeFromNeverDay implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly WriterInterface $writer
    ) {
    }

    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $never = $connection->fetchOne(
            $connection->select()
                ->from($this->setup->getTable('core_config_data'), 'config_id')
                ->where('path = ?', Config::TRAIN_DAY)
                ->where('scope = ?', 'default')
                ->where('value = ?', '-1')
        );
        if ($never) {
            $this->writer->save(Config::TRAIN_MODE, TrainMode::MANUAL);
            $this->writer->delete(Config::TRAIN_DAY);
        }

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

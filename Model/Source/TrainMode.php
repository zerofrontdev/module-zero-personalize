<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Training: every week on a day, or only when Train Now is pressed.
 */
class TrainMode implements OptionSourceInterface
{
    public const SCHEDULE = 'schedule';
    public const MANUAL = 'manual';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SCHEDULE, 'label' => __('On a schedule: every week')],
            ['value' => self::MANUAL, 'label' => __('By hand: only with Train Now')],
        ];
    }
}

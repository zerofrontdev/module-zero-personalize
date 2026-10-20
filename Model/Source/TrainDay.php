<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class TrainDay implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: int, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $days = [__('Sunday'), __('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'), __('Friday'), __('Saturday')];
        $options = [];
        foreach ($days as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        $options[] = ['value' => -1, 'label' => __('Never: only by hand')];
        return $options;
    }
}

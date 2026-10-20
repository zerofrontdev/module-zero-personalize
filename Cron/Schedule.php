<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Cron;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;
use Zero\Personalize\Model\Config;
use Zero\Personalize\Model\Pipeline;
use Zero\Personalize\Model\State;

/**
 * The weekly run, on its day (store time), when none ran in the last six
 * days. A store that trained with demo shoppers keeps them.
 */
class Schedule
{
    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly Pipeline $pipeline,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled() || $this->config->trainDay() < 0 || $this->state->isRunning()) {
            return;
        }
        if ((int) $this->timezone->date()->format('w') !== $this->config->trainDay()) {
            return;
        }
        $last = $this->state->get()['last'];
        if (!empty($last['finished_at']) && strtotime($last['finished_at'] . ' UTC') > time() - 6 * 86400) {
            return;
        }
        try {
            $this->pipeline->start(!empty($last['demo']));
        } catch (LocalizedException $e) {
            $this->logger->warning('Zero Personalize: weekly training not started: ' . $e->getMessage());
        }
    }
}

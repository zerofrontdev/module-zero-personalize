<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Cron;

use Zero\Personalize\Model\Pipeline;
use Zero\Personalize\Model\State;

class Advance
{
    public function __construct(
        private readonly State $state,
        private readonly Pipeline $pipeline
    ) {
    }

    public function execute(): void
    {
        if ($this->state->isRunning()) {
            $this->pipeline->advance();
        }
    }
}

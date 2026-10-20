<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Model;

use Magento\Framework\FlagManager;

/**
 * Where the store is with Personalize, kept in Magento's flag table:
 * the resources made (ARNs), the run in progress (its step and jobs), the
 * last run that finished, the last error.
 *
 * Steps of a run: setup (resources being made), import, train, batch, load;
 * then done, or failed with an error.
 */
class State
{
    public const IDLE = 'idle';
    public const SETUP = 'setup';
    public const IMPORT = 'import';
    public const TRAIN = 'train';
    public const BATCH = 'batch';
    public const LOAD = 'load';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const RUNNING = [self::SETUP, self::IMPORT, self::TRAIN, self::BATCH, self::LOAD];

    private const FLAG = 'zero_personalize_state';

    public function __construct(
        private readonly FlagManager $flags
    ) {
    }

    /**
     * @return array{step: string, resources: array<string, string>, run: array<string, mixed>, last: array<string, mixed>, error: string|null, updated_at: string|null}
     */
    public function get(): array
    {
        $data = $this->flags->getFlagData(self::FLAG);
        $data = is_array($data) ? $data : [];
        return [
            'step' => (string) ($data['step'] ?? self::IDLE),
            'resources' => (array) ($data['resources'] ?? []),
            'run' => (array) ($data['run'] ?? []),
            'last' => (array) ($data['last'] ?? []),
            'error' => isset($data['error']) ? (string) $data['error'] : null,
            'updated_at' => isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $changes merged into the state (top level)
     */
    public function save(array $changes): array
    {
        $state = array_merge($this->get(), $changes, ['updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->flags->saveFlag(self::FLAG, $state);
        return $state;
    }

    public function isRunning(): bool
    {
        return in_array($this->get()['step'], self::RUNNING, true);
    }

    public function clear(): void
    {
        $this->flags->deleteFlag(self::FLAG);
    }
}

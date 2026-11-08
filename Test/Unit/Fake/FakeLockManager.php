<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Personalize\Test\Unit\Fake;

use Magento\Framework\Lock\LockManagerInterface;

/**
 * An in-memory lock manager: the locks this process holds, names "held by
 * another process" (holdByOther), and every lock/unlock call in order.
 * (Zero_Personalize keeps its own copy: test code is not shared between modules.)
 */
class FakeLockManager implements LockManagerInterface
{
    /** @var array<string, true> */
    public array $held = [];
    /** @var array<string, true> */
    private array $other = [];
    /** @var list<array{0: string, 1: string, 2?: int}> */
    public array $calls = [];
    /** @var (\Closure(string, string): void)|null told of each lock/unlock, to order them among other calls */
    public ?\Closure $observer = null;

    public function holdByOther(string $name): void
    {
        $this->other[$name] = true;
    }

    public function lock(string $name, int $timeout = -1): bool
    {
        $this->calls[] = ['lock', $name, $timeout];
        $this->observer && ($this->observer)('lock', $name);
        if (isset($this->other[$name]) || isset($this->held[$name])) {
            return false;
        }
        $this->held[$name] = true;

        return true;
    }

    public function unlock(string $name): bool
    {
        $this->calls[] = ['unlock', $name];
        $this->observer && ($this->observer)('unlock', $name);
        $was = isset($this->held[$name]);
        unset($this->held[$name]);

        return $was;
    }

    public function isLocked(string $name): bool
    {
        return isset($this->held[$name]) || isset($this->other[$name]);
    }

    /** @return list<string> the names unlocked, in order */
    public function unlocked(): array
    {
        return array_values(array_map(
            static fn (array $call) => $call[1],
            array_filter($this->calls, static fn (array $call) => $call[0] === 'unlock')
        ));
    }
}

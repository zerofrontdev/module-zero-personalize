<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Zero\Personalize\Model\Config;

/**
 * Enabled per scope: a store view switched off hides both blocks there, one
 * switched on shows them even where the Default is off; the training and the
 * exports follow the Default value only. Training by hand means no
 * scheduled day; on a schedule, the chosen weekday.
 */
#[AllowMockObjectsWithoutExpectations]
class ConfigTest extends TestCase
{
    /**
     * @param array<string, bool> $default path => flag at the Default scope
     * @param array<int, array<string, bool>> $stores store id => path => flag (already resolved for that store view)
     */
    private function config(array $default, array $stores): Config
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(
            static function (string $path, string $type = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $store = null) use ($default, $stores): bool {
                if ($store === null || $type === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
                    return $default[$path] ?? false;
                }
                return $stores[(int) $store][$path] ?? ($default[$path] ?? false);
            }
        );

        return new Config($scope, $this->createMock(EncryptorInterface::class), $this->createMock(StoreManagerInterface::class));
    }

    public function testAStoreViewSwitchedOffHidesBothBlocksAndKeepsTheTraining(): void
    {
        $config = $this->config(
            [Config::ENABLED => true, Config::SIMILAR_ENABLED => true, Config::USER_ENABLED => true],
            [16 => [Config::ENABLED => false]]
        );
        self::assertTrue($config->isEnabled());
        self::assertTrue($config->isSimilarShown(1));
        self::assertTrue($config->isUserShown(1));
        self::assertFalse($config->isSimilarShown(16));
        self::assertFalse($config->isUserShown(16));
    }

    public function testAStoreViewSwitchedOnShowsThemWhereTheDefaultIsOff(): void
    {
        $config = $this->config(
            [Config::ENABLED => false, Config::SIMILAR_ENABLED => true, Config::USER_ENABLED => false],
            [1 => [Config::ENABLED => true]]
        );
        self::assertFalse($config->isEnabled(), 'no training while the Default is off');
        self::assertTrue($config->isSimilarShown(1));
        self::assertFalse($config->isUserShown(1), 'the block\'s own switch still applies');
        self::assertFalse($config->isSimilarShown(2));
    }

    public function testTrainingByHandHasNoScheduledDay(): void
    {
        $values = [Config::TRAIN_MODE => 'manual', Config::TRAIN_DAY => '3'];
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        $config = new Config($scope, $this->createMock(EncryptorInterface::class), $this->createMock(StoreManagerInterface::class));
        self::assertSame(-1, $config->trainDay());

        $values = [Config::TRAIN_MODE => 'schedule', Config::TRAIN_DAY => '3'];
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        $config = new Config($scope, $this->createMock(EncryptorInterface::class), $this->createMock(StoreManagerInterface::class));
        self::assertSame(3, $config->trainDay(), 'Wednesday');
    }
}

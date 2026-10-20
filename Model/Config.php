<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stores > Configuration > Zero > Recommendations.
 */
class Config
{
    public const ENABLED = 'zero_personalize/general/enabled';
    public const REGION = 'zero_personalize/aws/region';
    public const BUCKET = 'zero_personalize/aws/bucket';
    public const ROLE_ARN = 'zero_personalize/aws/role_arn';
    public const PREFIX = 'zero_personalize/aws/prefix';
    public const ACCESS_KEY = 'zero_personalize/aws/access_key';
    public const SECRET_KEY = 'zero_personalize/aws/secret_key';
    public const LOOKBACK_DAYS = 'zero_personalize/training/lookback_days';
    public const TRAIN_DAY = 'zero_personalize/training/day';
    public const SIMILAR_ENABLED = 'zero_personalize/storefront/similar_enabled';
    public const SIMILAR_TITLE = 'zero_personalize/storefront/similar_title';
    public const SIMILAR_COUNT = 'zero_personalize/storefront/similar_count';
    public const USER_ENABLED = 'zero_personalize/storefront/user_enabled';
    public const USER_TITLE = 'zero_personalize/storefront/user_title';
    public const USER_COUNT = 'zero_personalize/storefront/user_count';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::ENABLED);
    }

    public function region(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::REGION)) ?: 'us-east-1';
    }

    public function bucket(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::BUCKET));
    }

    public function roleArn(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::ROLE_ARN));
    }

    /**
     * What every AWS resource of this store is named after, so one account
     * can hold several stores (and a development copy): "zero-shop-example-com".
     */
    public function prefix(): string
    {
        $prefix = trim((string) $this->scopeConfig->getValue(self::PREFIX));
        if ($prefix === '') {
            $host = (string) parse_url((string) $this->storeManager->getStore(0)->getBaseUrl(), PHP_URL_HOST);
            $prefix = 'zero-' . ($host ?: 'store');
        }
        $prefix = strtolower((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $prefix));
        return trim(substr($prefix, 0, 40), '-');
    }

    /**
     * Access keys, for a store outside AWS; empty: the SDK's own chain
     * (the instance role on EC2, environment variables, ~/.aws).
     *
     * @return array{key: string, secret: string}|null
     */
    public function credentials(): ?array
    {
        $key = trim((string) $this->scopeConfig->getValue(self::ACCESS_KEY));
        $secret = (string) $this->scopeConfig->getValue(self::SECRET_KEY);
        if ($key === '' || $secret === '') {
            return null;
        }
        return ['key' => $key, 'secret' => $this->encryptor->decrypt($secret)];
    }

    public function lookbackDays(): int
    {
        return max(30, (int) $this->scopeConfig->getValue(self::LOOKBACK_DAYS));
    }

    /**
     * The weekday of the automatic training (0 Sunday … 6 Saturday); -1: never.
     */
    public function trainDay(): int
    {
        $value = $this->scopeConfig->getValue(self::TRAIN_DAY);
        return $value === null || $value === '' ? 1 : (int) $value;
    }

    public function isSimilarShown(int|string|null $store = null): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(self::SIMILAR_ENABLED, ScopeInterface::SCOPE_STORE, $store);
    }

    public function similarTitle(int|string|null $store = null): string
    {
        return trim((string) $this->scopeConfig->getValue(self::SIMILAR_TITLE, ScopeInterface::SCOPE_STORE, $store));
    }

    public function similarCount(int|string|null $store = null): int
    {
        return min(Pipeline::RESULTS, max(1, (int) $this->scopeConfig->getValue(self::SIMILAR_COUNT, ScopeInterface::SCOPE_STORE, $store)));
    }

    public function isUserShown(int|string|null $store = null): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(self::USER_ENABLED, ScopeInterface::SCOPE_STORE, $store);
    }

    public function userTitle(int|string|null $store = null): string
    {
        return trim((string) $this->scopeConfig->getValue(self::USER_TITLE, ScopeInterface::SCOPE_STORE, $store));
    }

    public function userCount(int|string|null $store = null): int
    {
        return min(Pipeline::RESULTS, max(1, (int) $this->scopeConfig->getValue(self::USER_COUNT, ScopeInterface::SCOPE_STORE, $store)));
    }
}

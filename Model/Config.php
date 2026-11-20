<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Every setting of the module, read in one place and typed.
 */
class Config
{
    public const ALLOW = 'allow';
    public const BLOCK = 'block';

    private const PREFIX = 'magelan_aivisibility/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag('general/enabled', $storeId);
    }

    public function managesRobots(?int $storeId = null): bool
    {
        return $this->flag('robots/enabled', $storeId);
    }

    /**
     * allow or block, for one kind of bot (training, search, user).
     */
    public function getPolicy(string $kind, ?int $storeId = null): string
    {
        return (string)$this->value('robots/' . $kind, $storeId) === self::BLOCK ? self::BLOCK : self::ALLOW;
    }

    /**
     * @return string[]
     */
    public function getAlwaysBlocked(?int $storeId = null): array
    {
        return $this->list((string)$this->value('robots/always_block', $storeId), ',');
    }

    /**
     * @return string[]
     */
    public function getAlwaysAllowed(?int $storeId = null): array
    {
        return $this->list((string)$this->value('robots/always_allow', $storeId), ',');
    }

    /**
     * Paths no AI bot needs: the cart, the account, search results.
     *
     * @return string[]
     */
    public function getPrivatePaths(?int $storeId = null): array
    {
        return array_values(array_filter(
            $this->list((string)$this->value('robots/private_paths', $storeId), "\n"),
            static fn (string $path): bool => str_starts_with($path, '/')
        ));
    }

    public function servesLlms(?int $storeId = null): bool
    {
        return $this->flag('llms/enabled', $storeId);
    }

    public function servesLlmsFull(?int $storeId = null): bool
    {
        return $this->flag('llms/full', $storeId);
    }

    public function getLlmsSummary(?int $storeId = null): string
    {
        return trim((string)$this->value('llms/summary', $storeId));
    }

    public function getLlmsAbout(?int $storeId = null): string
    {
        return trim((string)$this->value('llms/about', $storeId));
    }

    /**
     * @return string[] CMS page identifiers
     */
    public function getLlmsPages(?int $storeId = null): array
    {
        return $this->list((string)$this->value('llms/pages', $storeId), ',');
    }

    /**
     * @return string[] CMS page identifiers
     */
    public function getLlmsPolicies(?int $storeId = null): array
    {
        return $this->list((string)$this->value('llms/policies', $storeId), ',');
    }

    public function includesCategories(?int $storeId = null): bool
    {
        return $this->flag('llms/categories', $storeId);
    }

    public function getLlmsProductCount(?int $storeId = null): int
    {
        return max(0, min(500, (int)$this->value('llms/products', $storeId)));
    }

    public function getLlmsFullProductLimit(?int $storeId = null): int
    {
        return max(10, min(50000, (int)$this->value('llms/full_limit', $storeId) ?: 5000));
    }

    public function logsBots(?int $storeId = null): bool
    {
        return $this->flag('tracking/bots', $storeId);
    }

    public function tracksReferrals(?int $storeId = null): bool
    {
        return $this->flag('tracking/referrals', $storeId);
    }

    public function getAttributionDays(?int $storeId = null): int
    {
        return max(1, min(90, (int)$this->value('tracking/attribution_days', $storeId) ?: 30));
    }

    public function getHitRetentionDays(): int
    {
        return max(1, min(365, (int)$this->value('tracking/hit_days', null) ?: 30));
    }

    /**
     * The nightly page review of this store view (independent of General > Enabled: it reads the
     * shop, it changes nothing on it).
     */
    public function reviewsPages(?int $storeId = null): bool
    {
        return $this->flag('review/enabled', $storeId);
    }

    /**
     * How many pages one night checks; a bigger shop is checked in turns, oldest first.
     */
    public function getReviewLimit(?int $storeId = null): int
    {
        return max(20, min(20000, (int)$this->value('review/limit', $storeId) ?: 1000));
    }

    /**
     * The pause between two pages, so the review never loads the shop.
     */
    public function getReviewDelayMs(): int
    {
        return max(0, min(10000, (int)($this->value('review/delay_ms', null) ?? 1000)));
    }

    /**
     * The hour (shop time) the nightly review starts at.
     */
    public function getReviewHour(): int
    {
        return max(0, min(23, (int)($this->value('review/hour', null) ?? 2)));
    }

    public function logsMissingPages(?int $storeId = null): bool
    {
        return $this->flag('review/log_404', $storeId);
    }

    public function sendsWeeklyReview(): bool
    {
        return $this->flag('review/weekly', null);
    }

    /**
     * @return string[] e-mail addresses for the weekly review; empty: the general store contact
     */
    public function getWeeklyRecipients(): array
    {
        return array_values(array_filter(
            $this->list((string)$this->value('review/weekly_to', null), ','),
            static fn (string $email): bool => (bool)filter_var($email, FILTER_VALIDATE_EMAIL)
        ));
    }

    /**
     * user:password for a shop behind HTTP basic authentication (a staging site), so the review
     * and "What does a bot see?" read pages instead of 401. Empty: no credentials are sent.
     *
     * @return array{0: string, 1: string}|null
     */
    public function getReviewHttpAuth(): ?array
    {
        $value = trim((string)$this->value('review/http_auth', null));

        if ($value === '') {
            return null;
        }

        $value = trim($this->encryptor->decrypt($value));

        if (!str_contains($value, ':')) {
            return null;
        }

        [$user, $password] = explode(':', $value, 2);

        return [$user, $password];
    }

    /**
     * @return string[]
     */
    private function list(string $value, string $separator): array
    {
        return array_values(array_filter(array_map('trim', explode($separator, str_replace("\r", '', $value))), 'strlen'));
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @return mixed
     */
    private function value(string $path, ?int $storeId)
    {
        return $this->scopeConfig->getValue(self::PREFIX . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}

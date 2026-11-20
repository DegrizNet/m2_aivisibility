<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Block\Adminhtml;

use Magelan\AiVisibility\Model\AiSources;
use Magelan\AiVisibility\Model\Audit\Auditor;
use Magelan\AiVisibility\Model\BotCatalog;
use Magelan\AiVisibility\Model\Config;
use Magelan\AiVisibility\Model\Stats;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * The overview: which AI crawlers read the shop and what, which assistants send visitors, and how
 * many of those buy.
 */
class Dashboard extends Template
{
    public const PERIODS = [7, 30, 90];

    /** @var string */
    protected $_template = 'Magelan_AiVisibility::dashboard.phtml';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Stats $stats,
        private readonly BotCatalog $catalog,
        private readonly AiSources $sources,
        private readonly Auditor $auditor,
        private readonly Config $config,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getDays(): int
    {
        $days = (int)$this->getRequest()->getParam('days', 30);

        return in_array($days, self::PERIODS, true) ? $days : 30;
    }

    public function getPeriodUrl(int $days): string
    {
        return $this->getUrl('*/*/*', ['days' => $days]);
    }

    /**
     * Every known bot with its numbers for the period, those never seen included.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBots(): array
    {
        $totals = [];

        foreach ($this->stats->botTotals($this->getDays()) as $row) {
            $totals[(string)$row['bot']] = $row;
        }

        $access = $this->auditor->robotsAccess('/', $this->defaultStoreId());
        $rows = [];

        foreach ($this->catalog->all() as $token => $bot) {
            $rows[] = [
                'token' => $token,
                'company' => $bot['company'],
                'kind' => $bot['kind'],
                'logged' => $bot['logged'],
                'hits' => (int)($totals[$token]['hits'] ?? 0),
                'last_seen' => $totals[$token]['last_seen'] ?? null,
                'allowed' => $access[$token]['allowed'] ?? true,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['hits'], $a['token']] <=> [$a['hits'], $b['token']]);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopPaths(): array
    {
        return $this->stats->topPaths($this->getDays());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getReferrals(): array
    {
        $labels = $this->sources->labels();
        $rows = [];

        foreach ($this->stats->referralTotals($this->getDays()) as $row) {
            $row['label'] = $labels[$row['source']] ?? $row['source'];
            $row['conversion'] = (int)$row['visits'] > 0 ? round(100 * (int)$row['orders'] / (int)$row['visits'], 1) : 0;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    public function getHitsByDay(): array
    {
        return $this->stats->botHitsByDay($this->getDays());
    }

    public function formatMoney(float $amount): string
    {
        return $this->priceCurrency->format($amount, false, 2, null, $this->_storeManager->getStore($this->defaultStoreId())->getBaseCurrencyCode());
    }

    public function kindLabel(string $kind): string
    {
        return match ($kind) {
            BotCatalog::TRAINING => (string)__('Model training'),
            BotCatalog::SEARCH => (string)__('AI search index'),
            default => (string)__('Fetches for a user'),
        };
    }

    public function getLlmsUrl(): ?string
    {
        $storeId = $this->defaultStoreId();

        return $this->config->isEnabled($storeId) && $this->config->servesLlms($storeId)
            ? rtrim($this->_storeManager->getStore($storeId)->getBaseUrl(), '/') . '/llms.txt'
            : null;
    }

    public function getRobotsUrl(): string
    {
        return rtrim($this->_storeManager->getStore($this->defaultStoreId())->getBaseUrl(), '/') . '/robots.txt';
    }

    public function getAuditUrl(): string
    {
        return $this->getUrl('*/audit/index');
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'magelan_aivisibility']);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled($this->defaultStoreId());
    }

    private function defaultStoreId(): int
    {
        $store = $this->_storeManager->getDefaultStoreView();

        return $store ? (int)$store->getId() : 1;
    }
}

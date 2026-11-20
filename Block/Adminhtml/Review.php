<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Block\Adminhtml;

use Magelan\AiVisibility\Model\Seo\Issues;
use Magelan\AiVisibility\Model\Seo\Missing;
use Magelan\AiVisibility\Model\Seo\Review as ReviewModel;
use Magelan\AiVisibility\Model\Seo\ServerAddress;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;

/**
 * Marketing > AI Visibility > Page Review: the score, what to fix (with a link to the place where
 * it is fixed) and the addresses visitors did not find.
 */
class Review extends Template
{
    /** @var string */
    protected $_template = 'Magelan_AiVisibility::review.phtml';

    private const PAGES_PER_ISSUE = 30;

    private ?int $storeId = null;

    public function __construct(
        Context $context,
        private readonly ReviewModel $review,
        private readonly Issues $issues,
        private readonly Missing $missing,
        private readonly ResourceConnection $resource,
        private readonly AuthorizationInterface $authorization,
        private readonly Json $json,
        private readonly ServerAddress $serverAddress,
        private readonly ModuleManager $moduleManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getStoreId(): int
    {
        if ($this->storeId === null) {
            $requested = (int)$this->getRequest()->getParam('store');
            $ids = array_keys($this->getStores());
            $this->storeId = in_array($requested, $ids, true)
                ? $requested
                : (int)$this->_storeManager->getDefaultStoreView()?->getId();
        }

        return $this->storeId;
    }

    /**
     * @return array<int, string> store id => "Website / Store view"
     */
    public function getStores(): array
    {
        $out = [];
        foreach ($this->_storeManager->getStores() as $store) {
            $out[(int)$store->getId()] = $store->getWebsite()->getName() . ' / ' . $store->getName();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null the latest run, open or not
     */
    public function getRun(): ?array
    {
        return $this->review->latestRun($this->getStoreId());
    }

    public function isOpen(?array $run): bool
    {
        return $this->review->isOpen($run);
    }

    /**
     * @return array<string, mixed>|null the latest finished run
     */
    public function getDoneRun(): ?array
    {
        $runs = $this->getHistory();

        return $runs === [] ? null : $runs[count($runs) - 1];
    }

    /**
     * @return array<int, array<string, mixed>> finished runs, oldest first (at most 12)
     */
    public function getHistory(): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll($connection->select()
            ->from($this->resource->getTableName('magelan_ai_seo_run'))
            ->where('store_id = ?', $this->getStoreId())
            ->where('status = ?', ReviewModel::DONE)
            ->order('run_id DESC')
            ->limit(12));

        return array_reverse($rows);
    }

    /**
     * @return array<int, array{code: string, label: string, severity: string, advice: string, count: int, pages: array<int, array<string, mixed>>}>
     */
    public function getProblems(): array
    {
        $run = $this->getDoneRun();
        if ($run === null) {
            return [];
        }
        $out = [];
        foreach ((array)$this->json->unserialize((string)($run['summary'] ?: '{}')) as $code => $count) {
            $code = (string)$code;
            $out[] = [
                'code' => $code,
                'label' => $this->issues->label($code),
                'severity' => $this->issues->severity($code),
                'advice' => $this->issues->advice($code),
                'count' => (int)$count,
                'pages' => $this->pagesWith($code),
                'setting' => null,
            ];
            if ($code === 'product_data' && $this->richSnippetsOff()) {
                // The shop has the module that writes the price; it is only switched off.
                $out[count($out) - 1]['advice'] = (string)__('Magelan Rich Snippets is installed, but its product data is switched off. Switch on "Product JSON-LD" and the price, availability and rating go into every product page.');
                $out[count($out) - 1]['setting'] = $this->getUrl('adminhtml/system_config/edit', ['section' => 'magelan_richsnippets', 'store' => $this->getStoreId()]);
            }
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>> pages with this problem, worst score first
     */
    private function pagesWith(string $code): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll($connection->select()
            ->from($this->resource->getTableName('magelan_ai_seo_page'), ['path', 'entity_type', 'entity_id', 'title', 'score', 'issues'])
            ->where('store_id = ?', $this->getStoreId())
            ->where('source = ?', 'shop')
            ->where('issues LIKE ?', '%"' . $code . '"%')
            ->order(['score ASC', 'path ASC'])
            ->limit(self::PAGES_PER_ISSUE));
        foreach ($rows as &$row) {
            $issues = (array)$this->json->unserialize((string)$row['issues']);
            $row['detail'] = (string)($issues[$code] ?? '');
            $row['url'] = $this->pageUrl((string)$row['path']);
            $row['fix'] = $this->fixUrl((string)$row['entity_type'], (int)$row['entity_id']);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>> open 404 addresses with a suggested redirect target
     */
    public function getMissing(): array
    {
        $rows = $this->missing->rows($this->getStoreId(), Missing::OPEN, 50);
        foreach ($rows as &$row) {
            $row['suggestion'] = (string)$this->missing->suggest((int)$row['store_id'], (string)$row['path']);
        }

        return $rows;
    }

    public function countMissing(string $state): int
    {
        return $this->missing->count($this->getStoreId(), $state);
    }

    /**
     * Whether the CDN stops the server's own requests, so the review reads the web server directly.
     */
    public function readsDirectly(): bool
    {
        return $this->serverAddress->readsDirectly($this->getStoreId());
    }

    private function richSnippetsOff(): bool
    {
        return $this->moduleManager->isEnabled('Magelan_RichSnippets')
            && !$this->_scopeConfig->isSetFlag('magelan_richsnippets/general/product_jsonld', ScopeInterface::SCOPE_STORE, $this->getStoreId());
    }

    public function canRedirect(): bool
    {
        return $this->authorization->isAllowed('Magelan_AiVisibility::redirect');
    }

    public function pageUrl(string $path): string
    {
        return rtrim((string)$this->_storeManager->getStore($this->getStoreId())->getBaseUrl(UrlInterface::URL_TYPE_LINK, true), '/') . '/' . $path;
    }

    /**
     * Where the merchant fixes a page: the product, category or CMS page form; null for the home
     * page and addresses that are not an item.
     */
    public function fixUrl(string $type, int $id): ?string
    {
        return match ($type) {
            'product' => $this->getUrl('catalog/product/edit', ['id' => $id, 'store' => $this->getStoreId()]),
            'category' => $this->getUrl('catalog/category/edit', ['id' => $id, 'store' => $this->getStoreId()]),
            'cms-page' => $this->getUrl('cms/page/edit', ['page_id' => $id]),
            'home' => $this->getUrl('cms/page/index'),
            default => null,
        };
    }

    public function scoreClass(?int $score): string
    {
        return $score === null ? '' : ($score >= 80 ? '_ok' : ($score >= 60 ? '_warn' : '_no'));
    }

    public function severityClass(string $severity): string
    {
        return match ($severity) {
            Issues::HIGH => '_fail',
            Issues::MEDIUM => '_warn',
            default => '_low',
        };
    }

    public function severityLabel(string $severity): string
    {
        return (string)match ($severity) {
            Issues::HIGH => __('Fix first'),
            Issues::MEDIUM => __('Important'),
            default => __('Nice to fix'),
        };
    }

    public function formatWhen(?string $utc): string
    {
        return $utc ? $this->formatDate($utc, \IntlDateFormatter::MEDIUM, true) : '';
    }

    public function getStartUrl(): string
    {
        return $this->getUrl('*/*/start', ['store' => $this->getStoreId()]);
    }

    public function getStepUrl(): string
    {
        return $this->getUrl('*/*/step');
    }

    public function getRedirectUrl(): string
    {
        return $this->getUrl('*/*/redirect', ['store' => $this->getStoreId()]);
    }

    public function getIgnoreUrl(): string
    {
        return $this->getUrl('*/*/ignore', ['store' => $this->getStoreId()]);
    }

    public function getSwitchUrl(int $storeId): string
    {
        return $this->getUrl('*/*/index', ['store' => $storeId]);
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'magelan_aivisibility']);
    }
}

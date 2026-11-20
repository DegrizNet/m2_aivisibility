<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Llms;

use Magelan\AiVisibility\Model\Config;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Writes llms.txt for a store view, after llmstxt.org: a title, a one-paragraph summary, then
 * sections of links, each with a line saying what is behind it.
 *
 * llms.txt is the short version - the pages that explain the shop, the categories, the best
 * sellers. llms-full.txt adds every visible product with its price and description, for an
 * assistant that wants the whole catalogue in one read.
 */
class Builder
{
    private const PAGE_SIZE = 500;

    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ResourceConnection $resource
    ) {
    }

    public function build(int $storeId, bool $full): string
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore($storeId);
        $name = (string)$this->scopeValue('general/store_information/name', $storeId) ?: (string)$store->getFrontendName();
        $summary = $this->config->getLlmsSummary($storeId)
            ?: (string)$this->scopeValue('design/head/default_description', $storeId);

        $out = ['# ' . $this->line($name)];

        if ($summary !== '') {
            $out[] = '';
            $out[] = '> ' . $this->line($summary);
        }

        $about = $this->config->getLlmsAbout($storeId);

        if ($about !== '') {
            $out[] = '';
            $out[] = trim($about);
        }

        $out[] = '';
        $out[] = $this->facts($store, $storeId);

        $this->section($out, 'Pages', $this->pages($this->config->getLlmsPages($storeId), $storeId));
        $this->section($out, 'Policies', $this->pages($this->config->getLlmsPolicies($storeId), $storeId));

        if ($this->config->includesCategories($storeId)) {
            $this->section($out, 'Categories', $this->categories($store));
        }

        if ($full) {
            $this->section($out, 'Products', $this->allProducts($store, $this->config->getLlmsFullProductLimit($storeId)));
        } else {
            $count = $this->config->getLlmsProductCount($storeId);

            if ($count > 0) {
                $this->section($out, 'Best sellers', $this->bestSellers($store, $count));
            }

            if ($this->config->servesLlmsFull($storeId)) {
                $this->section($out, 'Optional', [
                    $this->link('Full catalogue', rtrim($store->getBaseUrl(), '/') . '/llms-full.txt', 'every product with its price and description'),
                ]);
            }
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * One line of facts an assistant is often asked: currency, language, where the shop ships.
     */
    private function facts(Store $store, int $storeId): string
    {
        $facts = [
            'Shop: ' . rtrim($store->getBaseUrl(), '/'),
            'Currency: ' . $store->getCurrentCurrencyCode(),
            'Language: ' . str_replace('_', '-', (string)$this->scopeValue('general/locale/code', $storeId)),
        ];

        $countries = (string)$this->scopeValue('general/country/allow', $storeId);

        if ($countries !== '' && substr_count($countries, ',') < 30) {
            $facts[] = 'Ships to: ' . str_replace(',', ', ', $countries);
        }

        $phone = (string)$this->scopeValue('general/store_information/phone', $storeId);
        $email = (string)$this->scopeValue('trans_email/ident_support/email', $storeId);

        if ($phone !== '') {
            $facts[] = 'Phone: ' . $phone;
        }

        if ($email !== '') {
            $facts[] = 'E-mail: ' . $email;
        }

        return implode("\n", array_map(static fn (string $fact): string => '- ' . $fact, $facts));
    }

    /**
     * @param string[] $out
     * @param string[] $items
     */
    private function section(array &$out, string $title, array $items): void
    {
        if ($items === []) {
            return;
        }

        $out[] = '';
        $out[] = '## ' . $title;
        $out[] = '';

        foreach ($items as $item) {
            $out[] = $item;
        }
    }

    /**
     * @param string[] $identifiers
     * @return string[]
     */
    private function pages(array $identifiers, int $storeId): array
    {
        if ($identifiers === []) {
            return [];
        }

        $collection = $this->pageCollectionFactory->create()
            ->addStoreFilter($storeId)
            ->addFieldToFilter('identifier', ['in' => $identifiers])
            ->addFieldToFilter('is_active', 1);

        $byIdentifier = [];

        foreach ($collection as $page) {
            $text = (string)$page->getMetaDescription() ?: $this->excerpt((string)$page->getContent(), 200);
            $byIdentifier[(string)$page->getIdentifier()] = $this->link(
                (string)$page->getTitle(),
                $this->storeManager->getStore($storeId)->getBaseUrl() . ($page->getIdentifier() === 'home' ? '' : $page->getIdentifier()),
                $text
            );
        }

        // In the order the shop chose them.
        return array_values(array_filter(array_map(
            static fn (string $identifier): ?string => $byIdentifier[$identifier] ?? null,
            $identifiers
        )));
    }

    /**
     * @return string[]
     */
    private function categories(Store $store): array
    {
        $rootId = (int)$store->getRootCategoryId();
        $collection = $this->categoryCollectionFactory->create()
            ->setStoreId((int)$store->getId())
            ->addAttributeToSelect(['name', 'description', 'meta_description'])
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('include_in_menu', 1)
            ->addAttributeToFilter('path', ['like' => '1/' . $rootId . '/%'])
            ->addAttributeToFilter('level', ['in' => [2, 3]])
            ->addUrlRewriteToResult()
            ->setOrder('path', 'ASC');

        $lines = [];

        /** @var Category $category */
        foreach ($collection as $category) {
            $text = (string)$category->getData('meta_description')
                ?: $this->excerpt((string)$category->getData('description'), 160);
            $line = $this->link((string)$category->getName(), (string)$category->getUrl(), $text);
            $lines[] = ((int)$category->getLevel() === 3 ? '  ' : '') . $line;
        }

        return $lines;
    }

    /**
     * @return string[]
     */
    private function bestSellers(Store $store, int $count): array
    {
        $connection = $this->resource->getConnection();
        $ids = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('sales_order_item'), ['product_id'])
                ->where('store_id = ?', (int)$store->getId())
                ->where('parent_item_id IS NULL')
                ->where('created_at >= ?', gmdate('Y-m-d H:i:s', time() - 365 * 86400))
                ->group('product_id')
                ->order('SUM(qty_ordered) DESC')
                ->limit($count * 3)
        ));

        $lines = $ids === [] ? [] : $this->productLines($store, $ids, $count, 200);

        // A new shop has sold nothing yet; its newest products stand in.
        if (count($lines) < $count) {
            $lines = array_merge($lines, $this->productLines($store, null, $count - count($lines), 200, $ids));
        }

        return $lines;
    }

    /**
     * @return string[]
     */
    private function allProducts(Store $store, int $limit): array
    {
        $lines = [];
        $page = 1;

        while (count($lines) < $limit) {
            $chunk = $this->productLines($store, null, min(self::PAGE_SIZE, $limit - count($lines)), 600, [], $page);

            if ($chunk === []) {
                break;
            }

            array_push($lines, ...$chunk);
            $page++;
        }

        return $lines;
    }

    /**
     * @param int[]|null $ids Only these, in this order; null for all, newest first
     * @param int[] $exclude
     * @return string[]
     */
    private function productLines(Store $store, ?array $ids, int $limit, int $textLength, array $exclude = [], int $page = 1): array
    {
        if ($limit <= 0) {
            return [];
        }

        $collection = $this->productCollectionFactory->create()
            ->setStoreId((int)$store->getId())
            ->addStoreFilter($store)
            ->addAttributeToSelect(['name', 'short_description', 'description', 'url_key'])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]])
            ->addPriceData(0, (int)$store->getWebsiteId())
            ->addUrlRewrite();

        if ($ids !== null) {
            $collection->addIdFilter($ids);
        } else {
            $collection->setOrder('created_at', 'DESC')->setPageSize($limit)->setCurPage($page);
        }

        if ($exclude !== []) {
            $collection->addIdFilter($exclude, true);
        }

        if ($ids === null && $page > 1 && $collection->getLastPageNumber() < $page) {
            return [];
        }

        $currency = (string)$store->getCurrentCurrencyCode();
        $rows = [];

        foreach ($collection as $product) {
            $text = $this->excerpt((string)$product->getData('short_description'), $textLength)
                ?: $this->excerpt((string)$product->getData('description'), $textLength);
            $price = (float)($product->getData('min_price') ?: $product->getData('final_price'));
            $suffix = $price > 0 ? ' Price: ' . number_format($price, 2, '.', '') . ' ' . $currency . '.' : '';
            $rows[(int)$product->getId()] = $this->link((string)$product->getName(), (string)$product->getProductUrl(), trim($text . $suffix));
        }

        if ($ids !== null) {
            $ordered = [];

            foreach ($ids as $id) {
                if (isset($rows[$id])) {
                    $ordered[] = $rows[$id];
                }
            }

            return array_slice($ordered, 0, $limit);
        }

        return array_values($rows);
    }

    public function link(string $title, string $url, string $text): string
    {
        $title = str_replace(['[', ']'], ['(', ')'], $this->line($title));
        $line = '- [' . $title . '](' . str_replace([' ', ')'], ['%20', '%29'], $url) . ')';
        $text = $this->line($text);

        return $text !== '' ? $line . ': ' . $text : $line;
    }

    /**
     * Plain text from HTML, on one line, cut at a word.
     */
    public function excerpt(string $html, int $length): string
    {
        // Page Builder and widgets leave {{...}} directives and style blocks behind.
        $html = (string)preg_replace(['~<(script|style)\b[^>]*>.*?</\1>~is', '~\{\{[^}]*\}\}~'], ' ', $html);
        $text = $this->line(html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>'], [' <br', ' </p>', ' </li>'], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim(($space !== false && $space > $length * 0.6 ? mb_substr($cut, 0, $space) : $cut), ' ,.;:') . '…';
    }

    private function line(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private function scopeValue(string $path, int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}

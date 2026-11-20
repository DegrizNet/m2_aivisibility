<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Audit;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\App\ResourceConnection;

/**
 * How much the catalogue gives an assistant to work with: products without the texts it quotes,
 * without pictures it can describe, without reviews it can weigh. Counted, not guessed - each line
 * is something a shop can fix product by product.
 */
class CatalogReadiness
{
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array<int, array{label: string, count: int, total: int}>
     */
    public function report(int $storeId): array
    {
        $total = $this->products($storeId)->getSize();
        $rows = [];

        foreach ([
            'description' => __('Products without a description'),
            'short_description' => __('Products without a short description'),
            'meta_description' => __('Products without a meta description'),
        ] as $attribute => $label) {
            $collection = $this->products($storeId)->addAttributeToFilter(
                $attribute,
                [['null' => true], ['eq' => '']],
                'left'
            );
            $rows[] = ['label' => (string)$label, 'count' => $collection->getSize(), 'total' => $total];
        }

        $rows[] = [
            'label' => (string)__('Products without a picture'),
            'count' => $this->products($storeId)->addAttributeToFilter(
                'image',
                [['null' => true], ['eq' => ''], ['eq' => 'no_selection']],
                'left'
            )->getSize(),
            'total' => $total,
        ];

        $rows[] = [
            'label' => (string)__('Products with pictures without a label of their own (the product name is used as alt text)'),
            'count' => $this->productsWithUnlabelledImages($storeId),
            'total' => $total,
        ];

        $rows[] = [
            'label' => (string)__('Products without a published review'),
            'count' => $total - $this->productsWithReviews($storeId),
            'total' => $total,
        ];

        $categories = $this->categoryCollectionFactory->create()
            ->setStoreId($storeId)
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('level', ['gteq' => 2]);
        $categoryTotal = $categories->getSize();
        $withoutDescription = $this->categoryCollectionFactory->create()
            ->setStoreId($storeId)
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('level', ['gteq' => 2])
            ->addAttributeToFilter('description', [['null' => true], ['eq' => '']], 'left')
            ->getSize();
        $rows[] = ['label' => (string)__('Categories without a description'), 'count' => $withoutDescription, 'total' => $categoryTotal];

        $pages = $this->pageCollectionFactory->create()->addStoreFilter($storeId)->addFieldToFilter('is_active', 1);
        $pageTotal = $pages->getSize();
        $pagesWithout = $this->pageCollectionFactory->create()
            ->addStoreFilter($storeId)
            ->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('meta_description', [['null' => true], ['eq' => '']])
            ->getSize();
        $rows[] = ['label' => (string)__('CMS pages without a meta description'), 'count' => $pagesWithout, 'total' => $pageTotal];

        return $rows;
    }

    private function products(int $storeId): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        return $this->productCollectionFactory->create()
            ->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]]);
    }

    private function productsWithUnlabelledImages(int $storeId): int
    {
        $collection = $this->products($storeId);
        $connection = $this->resource->getConnection();
        $link = $connection->tableColumnExists($this->resource->getTableName('catalog_product_entity'), 'row_id') ? 'row_id' : 'entity_id';

        $unlabelled = $connection->select()
            ->from(['g2e' => $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity')], [])
            ->join(['g' => $this->resource->getTableName('catalog_product_entity_media_gallery')], 'g.value_id = g2e.value_id', [])
            ->joinLeft(
                ['gv' => $this->resource->getTableName('catalog_product_entity_media_gallery_value')],
                'gv.value_id = g.value_id AND gv.store_id = 0 AND gv.' . $link . ' = g2e.' . $link,
                []
            )
            ->where('g2e.' . $link . ' = e.' . $link)
            ->where('g.media_type = ?', 'image')
            ->where('gv.label IS NULL OR gv.label = ""')
            ->columns(['g.value_id']);

        $collection->getSelect()->where('EXISTS (' . $unlabelled . ')');

        return $collection->getSize();
    }

    private function productsWithReviews(int $storeId): int
    {
        $connection = $this->resource->getConnection();

        if (!$connection->isTableExists($this->resource->getTableName('review'))) {
            return 0;
        }

        $collection = $this->products($storeId);
        $reviews = $connection->select()
            ->from(['r' => $this->resource->getTableName('review')], ['r.review_id'])
            ->join(['rs' => $this->resource->getTableName('review_store')], 'rs.review_id = r.review_id', [])
            ->where('r.entity_pk_value = e.entity_id')
            ->where('r.status_id = 1')
            ->where('rs.store_id = ?', $storeId);
        $collection->getSelect()->where('EXISTS (' . $reviews . ')');

        return $collection->getSize();
    }
}

<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Seo;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;

/**
 * The pages of a store view the way the sitemap sees them: the home page, every enabled and
 * visible product at its own address (not under a category), every active category and every
 * active CMS page. Disabled items are left out, so they are not reported as broken.
 */
class UrlSource
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductCollectionFactory $products,
        private readonly CategoryCollectionFactory $categories
    ) {
    }

    /**
     * @return array<string, array{entity_type: string, entity_id: int|null}> path => entity
     */
    public function pages(int $storeId): array
    {
        $out = ['' => ['entity_type' => 'home', 'entity_id' => null]];
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('url_rewrite');

        $productIds = $this->products->create()
            ->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]])
            ->getAllIds();
        $categoryIds = $this->categories->create()
            ->setStoreId($storeId)
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('level', ['gt' => 1])
            ->getAllIds();
        $pageIds = $connection->fetchCol(
            $connection->select()
                ->from(['p' => $this->resource->getTableName('cms_page')], ['page_id'])
                ->join(['s' => $this->resource->getTableName('cms_page_store')], 's.page_id = p.page_id', [])
                ->where('p.is_active = 1')
                ->where('s.store_id IN (?)', [0, $storeId])
                ->where('p.identifier NOT IN (?)', ['no-route', 'home', 'enable-cookies'])
        );

        foreach ([
            'product' => array_map('intval', $productIds),
            'category' => array_map('intval', $categoryIds),
            'cms-page' => array_map('intval', $pageIds),
        ] as $type => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
                $select = $connection->select()
                    ->from($table, ['request_path', 'entity_id'])
                    ->where('store_id = ?', $storeId)
                    ->where('redirect_type = 0')
                    ->where('entity_type = ?', $type)
                    ->where('entity_id IN (?)', $chunk);
                if ($type === 'product') {
                    $select->where('metadata IS NULL');
                }

                foreach ($connection->fetchAll($select) as $row) {
                    $out[(string)$row['request_path']] = ['entity_type' => $type, 'entity_id' => (int)$row['entity_id']];
                }
            }
        }

        return $out;
    }
}

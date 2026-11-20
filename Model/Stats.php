<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * The counters: AI bot visits per day, the latest of them one by one, visits and orders from AI
 * assistants per day. Every write is one INSERT ... ON DUPLICATE KEY UPDATE, so a busy shop pays
 * one cheap query per counted request and nothing for the rest.
 */
class Stats
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function countBot(int $storeId, string $bot, string $path, int $status): void
    {
        $connection = $this->resource->getConnection();
        $day = gmdate('Y-m-d');
        $path = mb_substr($path, 0, 255);

        $connection->insertOnDuplicate(
            $this->resource->getTableName('magelan_ai_bot_daily'),
            ['day' => $day, 'store_id' => $storeId, 'bot' => $bot, 'hits' => 1, 'last_path' => $path, 'last_seen' => gmdate('Y-m-d H:i:s')],
            ['hits' => new \Zend_Db_Expr('hits + 1'), 'last_path', 'last_seen']
        );

        $connection->insert($this->resource->getTableName('magelan_ai_bot_hit'), [
            'store_id' => $storeId,
            'bot' => $bot,
            'path' => $path,
            'status' => $status,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function countVisit(int $storeId, string $source): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName('magelan_ai_referral_daily'),
            ['day' => gmdate('Y-m-d'), 'store_id' => $storeId, 'source' => $source, 'visits' => 1],
            ['visits' => new \Zend_Db_Expr('visits + 1')]
        );
    }

    public function countOrder(int $storeId, string $source, int $orderId, float $baseTotal, string $landing): void
    {
        $connection = $this->resource->getConnection();
        $inserted = $connection->insertArray(
            $this->resource->getTableName('magelan_ai_order'),
            ['order_id', 'source', 'landing', 'created_at'],
            [[$orderId, $source, mb_substr($landing, 0, 255), gmdate('Y-m-d H:i:s')]],
            AdapterInterface::INSERT_IGNORE
        );

        // An order saved twice is still one order.
        if ((int)$inserted !== 1) {
            return;
        }

        $connection->insertOnDuplicate(
            $this->resource->getTableName('magelan_ai_referral_daily'),
            ['day' => gmdate('Y-m-d'), 'store_id' => $storeId, 'source' => $source, 'orders' => 1, 'revenue' => $baseTotal],
            ['orders' => new \Zend_Db_Expr('orders + 1'), 'revenue' => new \Zend_Db_Expr('revenue + VALUES(revenue)')]
        );
    }

    /**
     * @return array<int, array<string, mixed>> per bot: hits, days seen, last seen, last path
     */
    public function botTotals(int $days, ?int $storeId = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('magelan_ai_bot_daily'), [
                'bot',
                'hits' => 'SUM(hits)',
                'days' => 'COUNT(DISTINCT day)',
                'last_seen' => 'MAX(last_seen)',
            ])
            ->where('day >= ?', gmdate('Y-m-d', time() - $days * 86400))
            ->group('bot')
            ->order('hits DESC');

        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }

        return $connection->fetchAll($select);
    }

    /**
     * The pages AI bots read most, from the recent single hits.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topPaths(int $days, int $limit = 20): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('magelan_ai_bot_hit'), [
                    'path',
                    'hits' => 'COUNT(*)',
                    'bots' => 'GROUP_CONCAT(DISTINCT bot ORDER BY bot SEPARATOR ", ")',
                    'errors' => 'SUM(status >= 400)',
                ])
                ->where('created_at >= ?', gmdate('Y-m-d H:i:s', time() - $days * 86400))
                ->group('path')
                ->order('hits DESC')
                ->limit($limit)
        );
    }

    /**
     * @return array<int, array<string, mixed>> per source: visits, orders, revenue
     */
    public function referralTotals(int $days, ?int $storeId = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('magelan_ai_referral_daily'), [
                'source',
                'visits' => 'SUM(visits)',
                'orders' => 'SUM(orders)',
                'revenue' => 'SUM(revenue)',
            ])
            ->where('day >= ?', gmdate('Y-m-d', time() - $days * 86400))
            ->group('source')
            ->order('visits DESC');

        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }

        return $connection->fetchAll($select);
    }

    /**
     * Hits per day for the last days, for the chart: [day => hits].
     *
     * @return array<string, int>
     */
    public function botHitsByDay(int $days): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('magelan_ai_bot_daily'), ['day', 'SUM(hits)'])
                ->where('day >= ?', gmdate('Y-m-d', time() - $days * 86400))
                ->group('day')
        );

        $out = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * 86400);
            $out[$day] = (int)($rows[$day] ?? 0);
        }

        return $out;
    }

    public function purgeHits(int $days): int
    {
        return $this->resource->getConnection()->delete(
            $this->resource->getTableName('magelan_ai_bot_hit'),
            ['created_at < ?' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }
}

<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Seo;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewrite as UrlRewriteResource;
use Magento\UrlRewrite\Model\UrlRewriteFactory;

/**
 * Addresses visitors asked for that do not exist (404), one row per path and store view, and the
 * one-click way out: a permanent redirect (a normal URL rewrite) to a page that exists.
 *
 * Only the path, two counters, the time and the page that linked to it are kept: no visitor.
 */
class Missing
{
    public const OPEN = 'open';
    public const REDIRECTED = 'redirected';
    public const IGNORED = 'ignored';

    /** Requests that are not pages: files a theme or a scanner asks for. */
    private const NOT_PAGES = '~(^|/)(static|media|pub|errors|setup|admin|rest|graphql|soap)(/|$)|\.(js|css|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|txt|xml|json|php|env|git|bak|sql|zip|gz)$~i';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly UrlRewriteFactory $urlRewriteFactory,
        private readonly UrlRewriteResource $urlRewriteResource
    ) {
    }

    /**
     * Whether a path is worth logging: a page address, not a file or a probe.
     */
    public function isPage(string $path): bool
    {
        return $path !== '' && strlen($path) <= 512 && !preg_match(self::NOT_PAGES, $path);
    }

    public function record(int $storeId, string $path, string $referer, bool $bot): void
    {
        $path = ltrim($path, '/');
        if (!$this->isPage($path)) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $referer = $this->cleanReferer($referer);
        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate(
            $this->resource->getTableName('magelan_ai_seo_missing'),
            [
                'store_id' => $storeId,
                'path' => $path,
                'path_hash' => sha1($path),
                'hits' => $bot ? 0 : 1,
                'bot_hits' => $bot ? 1 : 0,
                'referer' => $referer,
                'first_at' => $now,
                'last_at' => $now,
            ],
            [
                'hits' => new \Zend_Db_Expr('hits + VALUES(hits)'),
                'bot_hits' => new \Zend_Db_Expr('bot_hits + VALUES(bot_hits)'),
                'last_at',
                'referer' => new \Zend_Db_Expr('COALESCE(VALUES(referer), referer)'),
                // A redirected path that answers 404 again lost its redirect: back on the list.
                'state' => new \Zend_Db_Expr("IF(state = 'redirected', 'open', state)"),
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(?int $storeId, string $state = self::OPEN, int $limit = 50): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('magelan_ai_seo_missing'))
            ->where('state = ?', $state)
            ->order(['hits DESC', 'bot_hits DESC', 'last_at DESC'])
            ->limit($limit);
        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }

        return $connection->fetchAll($select);
    }

    public function count(?int $storeId, string $state = self::OPEN): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('magelan_ai_seo_missing'), ['COUNT(*)'])
            ->where('state = ?', $state);
        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }

        return (int)$connection->fetchOne($select);
    }

    /**
     * The existing page whose address looks most like the missing one (an old URL key, a typo),
     * or null when nothing is close enough to suggest.
     */
    public function suggest(int $storeId, string $path): ?string
    {
        $slug = $this->slug($path);
        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', $slug) ?: [],
            static fn (string $word): bool => strlen($word) >= 3 && !ctype_digit($word)
        ));
        if ($words === []) {
            return null;
        }
        usort($words, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('url_rewrite'), ['request_path'])
            ->where('store_id = ?', $storeId)
            ->where('redirect_type = 0')
            ->where('(entity_type <> ? OR metadata IS NULL)', 'product')
            ->limit(300);
        $conditions = [];
        foreach (array_slice($words, 0, 3) as $word) {
            $conditions[] = $connection->quoteInto('request_path LIKE ?', '%' . $word . '%');
        }
        $select->where(implode(' OR ', $conditions));

        $best = null;
        $bestScore = 0.0;
        foreach ($connection->fetchCol($select) as $candidate) {
            similar_text($slug, $this->slug((string)$candidate), $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = (string)$candidate;
            }
        }

        return $bestScore >= 50.0 ? $best : null;
    }

    /**
     * Creates the permanent redirect and marks the path as done.
     *
     * @throws LocalizedException
     */
    public function redirect(int $missingId, string $target): string
    {
        $row = $this->row($missingId);
        $target = $this->cleanTarget($target);
        if ($target === ltrim((string)$row['path'], '/')) {
            throw new LocalizedException(__('The redirect cannot point to the missing address itself.'));
        }

        $connection = $this->resource->getConnection();
        $exists = $connection->fetchOne($connection->select()
            ->from($this->resource->getTableName('url_rewrite'), ['url_rewrite_id'])
            ->where('store_id = ?', (int)$row['store_id'])
            ->where('request_path = ?', (string)$row['path']));
        if ($exists) {
            throw new LocalizedException(__('A URL rewrite for "%1" exists already (Marketing > URL Rewrites).', $row['path']));
        }

        $rewrite = $this->urlRewriteFactory->create();
        $rewrite->setEntityType('custom')
            ->setEntityId(0)
            ->setRequestPath((string)$row['path'])
            ->setTargetPath($target)
            ->setRedirectType(301)
            ->setStoreId((int)$row['store_id'])
            ->setDescription((string)__('Page review: address not found (404)'));
        $this->urlRewriteResource->save($rewrite);

        $connection->update(
            $this->resource->getTableName('magelan_ai_seo_missing'),
            ['state' => self::REDIRECTED, 'target' => $target],
            ['missing_id = ?' => $missingId]
        );

        return $target;
    }

    /**
     * @throws LocalizedException
     */
    public function ignore(int $missingId): void
    {
        $this->row($missingId);
        $this->resource->getConnection()->update(
            $this->resource->getTableName('magelan_ai_seo_missing'),
            ['state' => self::IGNORED],
            ['missing_id = ?' => $missingId]
        );
    }

    /**
     * Rows nobody asked for in a long time go; redirected and ignored ones stay as a record.
     */
    public function purge(int $days = 90): int
    {
        return $this->resource->getConnection()->delete(
            $this->resource->getTableName('magelan_ai_seo_missing'),
            ['state = ?' => self::OPEN, 'last_at < ?' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }

    /**
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function row(int $missingId): array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow($connection->select()
            ->from($this->resource->getTableName('magelan_ai_seo_missing'))
            ->where('missing_id = ?', $missingId));
        if (!$row) {
            throw new LocalizedException(__('This address is no longer on the list.'));
        }

        return $row;
    }

    /**
     * A path below the store's base URL: "/new-page.html", "new-page.html" or a full URL of the shop.
     *
     * @throws LocalizedException
     */
    private function cleanTarget(string $target): string
    {
        $target = trim($target);
        if (preg_match('~^https?://~i', $target)) {
            $target = (string)parse_url($target, PHP_URL_PATH) . (parse_url($target, PHP_URL_QUERY) ? '?' . parse_url($target, PHP_URL_QUERY) : '');
        }
        $target = ltrim($target, '/');
        if (strlen($target) > 255 || preg_match('/[\s<>"]/', $target)) {
            throw new LocalizedException(__('Enter the address of an existing page, for example new-page.html.'));
        }

        return $target;
    }

    private function cleanReferer(string $referer): ?string
    {
        if ($referer === '' || !preg_match('~^https?://~i', $referer)) {
            return null;
        }
        $parts = parse_url($referer);

        return substr(($parts['host'] ?? '') . ($parts['path'] ?? ''), 0, 512) ?: null;
    }

    private function slug(string $path): string
    {
        $path = strtolower(trim($path, '/'));
        $path = (string)preg_replace('/\.(html?|php)$/', '', $path);

        return $path;
    }
}

<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Seo;

use Magelan\AiVisibility\Model\Audit\Fetcher;
use Magelan\AiVisibility\Model\Audit\PageAnalyzer;
use Magelan\AiVisibility\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The page review of one store view, in three phases a run walks through in short steps (the cron
 * every five minutes, or the admin page while it is open):
 *
 * 1. "running": every queued page is read like a search engine reads it and gets its own problems;
 * 2. "links":   the addresses those pages link to that are not pages of the shop are checked once;
 * 3. "done":    problems that need all pages (same titles, broken links) are added, and the score.
 *
 * The latest state of every page stays in one row, so a shop bigger than one night's limit is
 * reviewed in turns and the score always covers all of it.
 */
class Review
{
    public const RUNNING = 'running';
    public const LINKS = 'links';
    public const DONE = 'done';
    public const BLOCKED = 'blocked';

    /** Problems that need the other pages: recomputed at the end of every run. */
    private const CROSS = ['title_duplicate', 'desc_duplicate', 'links_broken', 'links_redirect'];

    private const LINK_CHECKS = 150;

    /** Links that are actions, not pages: store switcher, compare, cart and account links. */
    private const ACTIONS = '~(^|/)(uenc|___store|___from_store)/|^(stores/store|catalog/product_compare|checkout|customer|wishlist|sales|newsletter/subscriber|review/product/post|sendfriend|paypal|multishipping)(/|$)~';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly UrlSource $urlSource,
        private readonly Fetcher $fetcher,
        private readonly PageAnalyzer $analyzer,
        private readonly PageCheck $pageCheck,
        private readonly Issues $issues,
        private readonly Missing $missing,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LockManagerInterface $locks,
        private readonly Json $json,
        private readonly ServerAddress $serverAddress
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestRun(int $storeId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow($connection->select()
            ->from($this->table('magelan_ai_seo_run'))
            ->where('store_id = ?', $storeId)
            ->order('run_id DESC')
            ->limit(1));

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function run(int $runId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow($connection->select()->from($this->table('magelan_ai_seo_run'))->where('run_id = ?', $runId));

        return $row ?: null;
    }

    public function isOpen(?array $run): bool
    {
        return $run !== null && in_array($run['status'], [self::RUNNING, self::LINKS], true);
    }

    /**
     * Starts a run (or returns the one still open) and queues the pages it checks.
     */
    public function start(int $storeId, ?int $limit = null): int
    {
        $open = $this->latestRun($storeId);
        if ($this->isOpen($open)) {
            if (strtotime($open['started_at'] . ' UTC') > time() - 86400) {
                return (int)$open['run_id'];
            }
            $this->close((int)$open['run_id'], self::BLOCKED, (string)__('Stopped: it did not finish within a day.'));
        }

        $connection = $this->resource->getConnection();
        $connection->insert($this->table('magelan_ai_seo_run'), [
            'store_id' => $storeId,
            'status' => self::RUNNING,
            'started_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $runId = (int)$connection->lastInsertId($this->table('magelan_ai_seo_run'));
        $pages = $this->table('magelan_ai_seo_page');

        $rows = [];
        foreach ($this->urlSource->pages($storeId) as $path => $entity) {
            $rows[] = [
                'store_id' => $storeId,
                'path' => substr((string)$path, 0, 512),
                'path_hash' => sha1((string)$path),
                'source' => 'shop',
                'entity_type' => $entity['entity_type'],
                'entity_id' => $entity['entity_id'],
                'seen_run_id' => $runId,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $connection->insertOnDuplicate($pages, $chunk, ['source', 'entity_type', 'entity_id', 'seen_run_id']);
        }
        // Pages that left the shop leave the review; linked-only addresses are checked anew.
        $connection->delete($pages, [
            'store_id = ?' => $storeId,
            '(source = \'link\' OR seen_run_id IS NULL OR seen_run_id <> ?)' => $runId,
        ]);

        $limit = $limit ?? $this->config->getReviewLimit($storeId);
        $queue = $connection->fetchCol($connection->select()
            ->from($pages, ['page_id'])
            ->where('store_id = ?', $storeId)
            ->order(new \Zend_Db_Expr('checked_at IS NOT NULL'))
            ->order('checked_at ASC')
            ->order('page_id ASC')
            ->limit($limit));
        foreach (array_chunk($queue, 1000) as $chunk) {
            $connection->update($pages, ['queued_run_id' => $runId], ['page_id IN (?)' => $chunk]);
        }
        $connection->update($this->table('magelan_ai_seo_run'), ['queued' => count($queue)], ['run_id = ?' => $runId]);

        return $runId;
    }

    /**
     * Works on a run for at most this many seconds.
     *
     * @return array<string, mixed> the run afterwards
     */
    public function step(int $runId, int $seconds, ?int $delayMs = null): array
    {
        $run = $this->run($runId);
        if (!$this->isOpen($run)) {
            return (array)$run;
        }
        $lock = 'magelan_ai_review_' . $runId;
        if (!$this->locks->lock($lock, 0)) {
            return $run;
        }

        try {
            $deadline = microtime(true) + $seconds;
            $delayMs = $delayMs ?? $this->config->getReviewDelayMs();
            $storeId = (int)$run['store_id'];
            $base = $this->baseUrl($storeId);

            while (microtime(true) < $deadline) {
                $page = $this->nextQueued($runId);
                if ($page === null) {
                    if ($run['status'] === self::RUNNING) {
                        $this->queueLinks($runId, $storeId, $base);
                        $run['status'] = self::LINKS;
                        $this->resource->getConnection()->update($this->table('magelan_ai_seo_run'), ['status' => self::LINKS], ['run_id = ?' => $runId]);
                        continue;
                    }
                    $this->finish($runId, $storeId);
                    break;
                }
                if (!$this->checkPage($runId, $page, $base)) {
                    break;
                }
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        } finally {
            $this->locks->unlock($lock);
        }

        return (array)$this->run($runId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nextQueued(int $runId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow($connection->select()
            ->from($this->table('magelan_ai_seo_page'))
            ->where('queued_run_id = ?', $runId)
            ->where('checked_run_id IS NULL OR checked_run_id <> ?', $runId)
            ->order('page_id ASC')
            ->limit(1));

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $page
     * @return bool false when the run has to stop (a CDN stops the review)
     */
    private function checkPage(int $runId, array $page, string $base): bool
    {
        $connection = $this->resource->getConnection();
        $url = $base . $page['path'];
        $storeId = (int)$page['store_id'];
        $update = ['checked_run_id' => $runId, 'checked_at' => gmdate('Y-m-d H:i:s')];
        $address = $this->serverAddress->get();
        $direct = $address !== null && $this->serverAddress->readsDirectly($storeId);

        $fetched = $this->fetch($url, $direct ? $address : null);
        if (!$direct && $this->fetcher->isChallenge($fetched)) {
            // The CDN stops the server's own requests; real visitors and crawlers come from
            // elsewhere. Read the page from the web server directly from now on.
            $challenge = $fetched['status'];
            $fetched = $address !== null ? $this->fetch($url, $address) : $fetched;
            if ($address === null || $fetched['status'] === 0 || $this->fetcher->isChallenge($fetched)) {
                $this->close($runId, self::BLOCKED, (string)__('The CDN in front of the shop answered the review with a bot challenge (HTTP %1). Open this page in the admin once (so the review learns the address of the web server), or allow the user agent "MagelanPageReview" in the CDN.', $challenge));

                return false;
            }
            $this->serverAddress->setReadsDirectly($storeId, true);
        }

        $update += [
            'http_status' => $fetched['first_status'] ?: $fetched['status'],
            'final_status' => $fetched['status'],
            'hops' => $fetched['hops'],
            'final_url' => substr($fetched['url'], 0, 1024),
            'ms' => $fetched['ms'],
        ];

        if ($page['source'] === 'shop') {
            $facts = $fetched['status'] > 0 && $fetched['status'] < 400 && $fetched['body'] !== ''
                ? $this->analyzer->analyze($fetched['body'], $fetched['status'], $fetched['headers'], $fetched['url'])['facts']
                : [];
            $issues = $this->pageCheck->issues($facts, $fetched, $url, (string)$page['entity_type']);
            $update += [
                'title' => mb_substr((string)($facts['title'] ?? ''), 0, 512),
                'description' => mb_substr((string)($facts['description'] ?? ''), 0, 1024),
                'issues' => $this->json->serialize($issues),
                'score' => $this->issues->score($issues),
                'links' => $this->json->serialize($this->relativeLinks((array)($facts['links'] ?? []), $base)),
            ];
        }

        $connection->update($this->table('magelan_ai_seo_page'), $update, ['page_id = ?' => (int)$page['page_id']]);
        if ($page['source'] === 'shop') {
            $connection->update($this->table('magelan_ai_seo_run'), ['checked' => new \Zend_Db_Expr('checked + 1')], ['run_id = ?' => $runId]);
        }

        return true;
    }

    /**
     * @return array{status: int, first_status: int, hops: int, url: string, ms: int, headers: array<string, string>, body: string}
     */
    private function fetch(string $url, ?string $address): array
    {
        try {
            return $this->fetcher->fetch($url, Fetcher::REVIEW_AGENT, $address, 30);
        } catch (LocalizedException $e) {
            return ['status' => 0, 'first_status' => 0, 'hops' => 0, 'url' => $url, 'ms' => 0, 'headers' => [], 'body' => ''];
        }
    }

    /**
     * The addresses the pages link to that are not pages of the shop: checked once per run, the
     * most linked first.
     */
    private function queueLinks(int $runId, int $storeId, string $base): void
    {
        $connection = $this->resource->getConnection();
        $pages = $this->table('magelan_ai_seo_page');
        $known = array_flip($connection->fetchCol($connection->select()->from($pages, ['path'])->where('store_id = ?', $storeId)));
        $private = $this->config->getPrivatePaths($storeId);
        $count = [];

        foreach ($connection->fetchCol($connection->select()->from($pages, ['links'])->where('store_id = ?', $storeId)->where('links IS NOT NULL')) as $links) {
            foreach ((array)$this->json->unserialize((string)$links) as $path) {
                $path = (string)$path;
                if (isset($known[$path]) || !$this->missing->isPage($path) || preg_match(self::ACTIONS, $path)
                    || $this->isPrivate('/' . $path, $private)
                ) {
                    continue;
                }
                $count[$path] = ($count[$path] ?? 0) + 1;
            }
        }
        arsort($count);

        $rows = [];
        foreach (array_slice(array_keys($count), 0, self::LINK_CHECKS) as $path) {
            $rows[] = [
                'store_id' => $storeId,
                'path' => substr((string)$path, 0, 512),
                'path_hash' => sha1((string)$path),
                'source' => 'link',
                'seen_run_id' => $runId,
                'queued_run_id' => $runId,
            ];
        }
        if ($rows !== []) {
            $connection->insertOnDuplicate($pages, $rows, ['queued_run_id']);
        }
    }

    /**
     * Adds the problems that need all pages, the page scores and the run's score.
     */
    private function finish(int $runId, int $storeId): void
    {
        $connection = $this->resource->getConnection();
        $pages = $this->table('magelan_ai_seo_page');
        $state = [];
        foreach ($connection->fetchAll($connection->select()
            ->from($pages, ['path', 'final_status', 'hops'])
            ->where('store_id = ?', $storeId)
            ->where('checked_at IS NOT NULL')) as $row) {
            $state[(string)$row['path']] = [(int)$row['final_status'], (int)$row['hops']];
        }

        $rows = $connection->fetchAll($connection->select()
            ->from($pages, ['page_id', 'path', 'title', 'description', 'issues', 'links', 'final_status'])
            ->where('store_id = ?', $storeId)
            ->where('source = ?', 'shop')
            ->where('checked_at IS NOT NULL'));

        $titles = [];
        $descriptions = [];
        $own = [];
        foreach ($rows as $row) {
            $issues = array_diff_key((array)$this->json->unserialize((string)($row['issues'] ?: '{}')), array_flip(self::CROSS));
            $own[(int)$row['page_id']] = $issues;
            // Pages that are not meant to be found on their own do not count as duplicates.
            if ((int)$row['final_status'] !== 200 || isset($issues['noindex']) || isset($issues['canonical_other']) || isset($issues['redirect'])) {
                continue;
            }
            if (trim((string)$row['title']) !== '') {
                $titles[mb_strtolower(trim((string)$row['title']))][] = (int)$row['page_id'];
            }
            if (trim((string)$row['description']) !== '') {
                $descriptions[mb_strtolower(trim((string)$row['description']))][] = (int)$row['page_id'];
            }
        }

        $summary = [];
        $scores = [];
        foreach ($rows as $row) {
            $id = (int)$row['page_id'];
            $issues = $own[$id];
            $title = mb_strtolower(trim((string)$row['title']));
            if ($title !== '' && count($titles[$title] ?? []) > 1 && in_array($id, $titles[$title], true)) {
                $issues['title_duplicate'] = (string)__('%1 pages', count($titles[$title]));
            }
            $description = mb_strtolower(trim((string)$row['description']));
            if ($description !== '' && count($descriptions[$description] ?? []) > 1 && in_array($id, $descriptions[$description], true)) {
                $issues['desc_duplicate'] = (string)__('%1 pages', count($descriptions[$description]));
            }

            $broken = [];
            $redirecting = [];
            foreach ((array)$this->json->unserialize((string)($row['links'] ?: '[]')) as $path) {
                // Links stored by an earlier run may still hold CDN paths (Cloudflare /cdn-cgi/).
                if (str_starts_with((string)$path, 'cdn-cgi/')) {
                    continue;
                }
                [$status, $hops] = $state[(string)$path] ?? [null, 0];
                if ($status === null) {
                    continue;
                }
                if ($status >= 400 || $status === 0) {
                    $broken[] = '/' . $path;
                } elseif ($hops > 0) {
                    $redirecting[] = '/' . $path;
                }
            }
            if ($broken !== []) {
                $issues['links_broken'] = $this->listDetail($broken);
            }
            if ($redirecting !== []) {
                $issues['links_redirect'] = $this->listDetail($redirecting);
            }

            $issues = $this->ordered($issues);
            $score = $this->issues->score($issues);
            $scores[] = $score;
            foreach (array_keys($issues) as $code) {
                $summary[$code] = ($summary[$code] ?? 0) + 1;
            }
            $connection->update($pages, ['issues' => $this->json->serialize($issues), 'score' => $score], ['page_id = ?' => $id]);
        }

        $connection->update($this->table('magelan_ai_seo_run'), [
            'status' => self::DONE,
            'pages' => count($rows),
            'score' => $scores === [] ? null : (int)round(array_sum($scores) / count($scores)),
            'summary' => $this->json->serialize($this->ordered($summary)),
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ], ['run_id = ?' => $runId]);
        $connection->update($pages, ['queued_run_id' => null], ['queued_run_id = ?' => $runId]);
    }

    private function close(int $runId, string $status, string $note): void
    {
        $connection = $this->resource->getConnection();
        $connection->update($this->table('magelan_ai_seo_run'), [
            'status' => $status,
            'note' => mb_substr($note, 0, 255),
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ], ['run_id = ?' => $runId]);
        $connection->update($this->table('magelan_ai_seo_page'), ['queued_run_id' => null], ['queued_run_id = ?' => $runId]);
    }

    /**
     * @param string[] $links absolute paths ("/si/page.html")
     * @return string[] paths below the store's base URL ("page.html"); links to other store views are left out
     */
    public function relativeLinks(array $links, string $base): array
    {
        $basePath = '/' . ltrim((string)parse_url($base, PHP_URL_PATH), '/');
        $out = [];
        foreach ($links as $link) {
            $link = (string)$link;
            if (!str_starts_with($link, $basePath)) {
                continue;
            }
            $out[] = substr($link, strlen($basePath));
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $values code => value
     * @return array<string, mixed> in the catalogue's order, most serious first
     */
    private function ordered(array $values): array
    {
        $out = [];
        foreach ($this->issues->codes() as $code) {
            if (array_key_exists($code, $values)) {
                $out[$code] = $values[$code];
            }
        }

        return $out;
    }

    /**
     * @param string[] $paths
     */
    private function listDetail(array $paths): string
    {
        $shown = array_slice($paths, 0, 5);

        return implode(', ', $shown) . (count($paths) > 5 ? ' ' . (string)__('and %1 more', count($paths) - 5) : '');
    }

    /**
     * @param string[] $private
     */
    private function isPrivate(string $path, array $private): bool
    {
        foreach ($private as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function baseUrl(int $storeId): string
    {
        return rtrim((string)$this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK, true), '/') . '/';
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}

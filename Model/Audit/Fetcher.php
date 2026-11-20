<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Audit;

use Magelan\AiVisibility\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Fetches a page of the shop over the network with an AI crawler's user agent - so a firewall,
 * a CDN rule or a bot blocker that turns the real crawler away turns this one away too, and the
 * audit shows it.
 *
 * The request carries a header the bot log ignores, so an audit never counts as a bot visit.
 *
 * Behind HTTP basic authentication (a staging site) the credentials from the settings go with
 * the request - only to the shop's own hosts, never to an address a redirect points elsewhere.
 */
class Fetcher
{
    public const AUDIT_HEADER = 'X-Magelan-Ai-Audit';

    /** The page review reads the shop as a search engine would, under its own name. */
    public const REVIEW_AGENT = 'review';

    private const REVIEW_USER_AGENT = 'Mozilla/5.0 (compatible; MagelanPageReview/1.0; +https://www.degriz.net/)';

    private const AGENTS = [
        'GPTBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
        'OAI-SearchBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)',
        'ChatGPT-User' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot',
        'ClaudeBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
        'PerplexityBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
    ];

    /** @var string[]|null lower-case host names of all store views */
    private ?array $shopHosts = null;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Whether the answer is a CDN's bot challenge rather than the page: Cloudflare's "Just a
     * moment..." and the like.
     *
     * @param array{status: int, headers: array<string, string>, body: string} $response
     */
    public function isChallenge(array $response): bool
    {
        $headers = $response['headers'];

        if (isset($headers['cf-mitigated'])) {
            return true;
        }

        return in_array($response['status'], [403, 429, 503], true)
            && (str_contains(strtolower($headers['server'] ?? ''), 'cloudflare')
                || str_contains($response['body'], 'challenge-platform')
                || str_contains($response['body'], '<title>Just a moment'));
    }

    /**
     * @return string[]
     */
    public function agents(): array
    {
        return array_keys(self::AGENTS);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string, url: string, ms: int, hops: int, first_status: int}
     * @throws LocalizedException
     */
    public function fetch(string $url, string $agent, ?string $directAddress = null, int $timeout = 45): array
    {
        $userAgent = $agent === self::REVIEW_AGENT ? self::REVIEW_USER_AGENT : (self::AGENTS[$agent] ?? self::AGENTS['GPTBot']);
        $started = microtime(true);
        $firstStatus = 0;

        for ($hop = 0; $hop <= 5; $hop++) {
            if (!preg_match('~^https?://~i', $url)) {
                throw new LocalizedException(__('Enter a full address starting with https://'));
            }

            $client = $this->curlFactory->create();
            // A page not in the cache yet can take a while to build the first time.
            $client->setTimeout($timeout);
            $client->addHeader('User-Agent', $userAgent);
            $client->addHeader(self::AUDIT_HEADER, '1');
            $client->addHeader('Accept', 'text/html,application/xhtml+xml');

            $auth = $this->config->getReviewHttpAuth();

            if ($auth !== null && $this->isShopHost($url)) {
                $client->setCredentials($auth[0], $auth[1]);
            }

            if ($directAddress !== null) {
                // Straight to this server, past any CDN in front of it: the host name stays, so
                // the virtual host and the certificate are the shop's own.
                $parts = parse_url($url);
                $port = (int)($parts['port'] ?? (($parts['scheme'] ?? 'https') === 'http' ? 80 : 443));
                $client->setOption(CURLOPT_RESOLVE, [($parts['host'] ?? '') . ':' . $port . ':' . $directAddress]);
            }

            try {
                $client->get($url);
            } catch (\Throwable $e) {
                throw new LocalizedException(__('The page could not be fetched: %1', $e->getMessage()));
            }

            $status = $client->getStatus();
            $firstStatus = $firstStatus ?: $status;
            $headers = [];

            foreach ($client->getHeaders() as $name => $value) {
                $headers[strtolower((string)$name)] = is_array($value) ? implode(', ', $value) : (string)$value;
            }

            if (in_array($status, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {
                $location = $headers['location'];

                if (!preg_match('~^https?://~i', $location)) {
                    $parts = parse_url($url);
                    $location = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . '/' . ltrim($location, '/');
                }

                $url = $location;
                continue;
            }

            return [
                'status' => $status,
                'headers' => $headers,
                'body' => $client->getBody(),
                'url' => $url,
                'ms' => (int)round((microtime(true) - $started) * 1000),
                'hops' => $hop,
                'first_status' => $firstStatus,
            ];
        }

        throw new LocalizedException(__('Too many redirects.'));
    }

    private function isShopHost(string $url): bool
    {
        if ($this->shopHosts === null) {
            $this->shopHosts = [];

            foreach ($this->storeManager->getStores() as $store) {
                foreach ([false, true] as $secure) {
                    $host = parse_url((string)$store->getBaseUrl(UrlInterface::URL_TYPE_WEB, $secure), PHP_URL_HOST);

                    if (is_string($host) && $host !== '') {
                        $this->shopHosts[] = strtolower($host);
                    }
                }
            }
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), $this->shopHosts, true);
    }
}

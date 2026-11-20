<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Observer;

use Magelan\AiVisibility\Model\Audit\Fetcher;
use Magelan\AiVisibility\Model\BotCatalog;
use Magelan\AiVisibility\Model\Config;
use Magelan\AiVisibility\Model\Stats;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Counts a request from a known AI crawler, just before the response goes out.
 *
 * This event fires for pages served from Magento's built-in page cache too, so cached pages are
 * counted. Pages Varnish or a CDN answers never reach PHP and can not be counted here.
 *
 * For everybody else it costs one regular expression on the user agent.
 */
class LogAiBot implements ObserverInterface
{
    public function __construct(
        private readonly BotCatalog $catalog,
        private readonly Config $config,
        private readonly Stats $stats,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $request = $observer->getData('request');
        $response = $observer->getData('response');

        // Our own audit fetches pages as a bot; it is not a visit.
        if (!$request instanceof HttpRequest || $request->getHeader(Fetcher::AUDIT_HEADER)) {
            return;
        }

        $bot = $this->catalog->match((string)$request->getHeader('User-Agent'));

        if ($bot === null) {
            return;
        }

        try {
            $storeId = (int)$this->storeManager->getStore()->getId();

            if (!$this->config->isEnabled($storeId) || !$this->config->logsBots($storeId)) {
                return;
            }

            // The path without its query: a query can carry a token or an e-mail address.
            $path = (string)parse_url((string)$request->getRequestUri(), PHP_URL_PATH);
            $status = $response instanceof HttpResponse ? (int)$response->getHttpResponseCode() : 200;

            $this->stats->countBot($storeId, $bot, $path !== '' ? $path : '/', $status ?: 200);
        } catch (\Throwable $e) {
            $this->logger->warning('Magelan_AiVisibility: bot visit not counted: ' . $e->getMessage());
        }
    }
}

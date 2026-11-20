<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Observer;

use Magelan\AiVisibility\Model\Audit\Fetcher;
use Magelan\AiVisibility\Model\Config;
use Magelan\AiVisibility\Model\Seo\Missing;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Counts every storefront answer "404 Not Found" just before it is sent: also the ones the
 * built-in full page cache serves (Magento caches 404 pages), which never reach a controller.
 * Behind Varnish only the first request of each cached 404 reaches the shop.
 */
class LogMissingPage implements ObserverInterface
{
    private const BOTS = '~bot|crawl|spider|slurp|scan|curl|wget|python|java/|go-http|headless|preview|facebookexternalhit~i';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly Missing $missing,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $response = $observer->getEvent()->getData('response');
        $request = $observer->getEvent()->getData('request');
        if (!$response instanceof HttpResponse || $response->getHttpResponseCode() !== 404 || !$request instanceof HttpRequest) {
            return;
        }

        try {
            if (!$request->isGet() || $request->getHeader(Fetcher::AUDIT_HEADER)) {
                return;
            }
            $storeId = (int)$this->storeManager->getStore()->getId();
            if (!$this->config->logsMissingPages($storeId)) {
                return;
            }
            $this->missing->record(
                $storeId,
                (string)$request->getPathInfo(),
                (string)$request->getServer('HTTP_REFERER'),
                (bool)preg_match(self::BOTS, (string)$request->getServer('HTTP_USER_AGENT'))
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Magelan AI visibility: 404 log: ' . $e->getMessage());
        }
    }
}

<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Observer;

use Magelan\AiVisibility\Model\AiSources;
use Magelan\AiVisibility\Model\Config;
use Magelan\AiVisibility\Model\Stats;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Credits an order to the AI assistant the buyer came from, when they came from one within the
 * attribution window (the cookie the tracking script set lives exactly that long).
 */
class AttributeOrder implements ObserverInterface
{
    public const COOKIE = 'mgl_ai_src';

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly AiSources $sources,
        private readonly Config $config,
        private readonly Stats $stats,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getData('order');

        if (!$order instanceof Order || !$order->getEntityId()) {
            return;
        }

        $cookie = (string)$this->cookieManager->getCookie(self::COOKIE);

        if ($cookie === '') {
            return;
        }

        [$source, $landing] = array_pad(explode('|', $cookie, 2), 2, '');
        $storeId = (int)$order->getStoreId();

        if (!$this->sources->isKnown($source) || !$this->config->isEnabled($storeId) || !$this->config->tracksReferrals($storeId)) {
            return;
        }

        try {
            $this->stats->countOrder(
                $storeId,
                $source,
                (int)$order->getEntityId(),
                (float)$order->getBaseGrandTotal(),
                (string)parse_url(rawurldecode($landing), PHP_URL_PATH)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Magelan_AiVisibility: order not attributed: ' . $e->getMessage());
        }
    }
}

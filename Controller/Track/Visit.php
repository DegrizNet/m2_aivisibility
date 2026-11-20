<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Controller\Track;

use Magelan\AiVisibility\Model\AiSources;
use Magelan\AiVisibility\Model\Config;
use Magelan\AiVisibility\Model\Stats;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Counts one visit that arrived from an AI assistant. Sent once per browser session by the
 * tracking script with navigator.sendBeacon.
 *
 * A beacon can not carry a form key, and this endpoint only ever adds one to a counter of a known
 * source, so it is exempt from the CSRF check - there is nothing here a forged request could do
 * that a real visit could not.
 */
class Visit implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly AiSources $sources,
        private readonly Stats $stats,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): Raw
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $source = (string)$this->request->getParam('source', '');

        if ($this->config->isEnabled($storeId)
            && $this->config->tracksReferrals($storeId)
            && $this->sources->isKnown($source)
        ) {
            $this->stats->countVisit($storeId, $source);
        }

        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(204);
        $result->setHeader('Cache-Control', 'no-store', true);

        return $result->setContents('');
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}

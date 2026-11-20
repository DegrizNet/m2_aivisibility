<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Block;

use Magelan\AiVisibility\Model\AiSources;
use Magelan\AiVisibility\Model\Config;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * The script tag of the AI referral tracker. The same for every visitor, so cached pages are fine.
 */
class Tracker extends Template
{
    /** @var string */
    protected $_template = 'Magelan_AiVisibility::tracker.phtml';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly AiSources $sources,
        private readonly Json $json,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isActive(): bool
    {
        $storeId = (int)$this->_storeManager->getStore()->getId();

        return $this->config->isEnabled($storeId) && $this->config->tracksReferrals($storeId);
    }

    public function getConfigJson(): string
    {
        return $this->json->serialize([
            'url' => $this->getUrl('magelan_ai/track/visit'),
            'days' => $this->config->getAttributionDays((int)$this->_storeManager->getStore()->getId()),
            'sources' => $this->sources->forScript(),
            // With Magento's cookie restriction mode on, nothing is stored before the visitor agrees.
            'consent' => $this->_scopeConfig->isSetFlag('web/cookie/cookie_restriction', 'store'),
        ]);
    }
}

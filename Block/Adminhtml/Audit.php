<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Block\Adminhtml;

use Magelan\AiVisibility\Model\Audit\Auditor;
use Magelan\AiVisibility\Model\Audit\CatalogReadiness;
use Magelan\AiVisibility\Model\BotCatalog;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Session;

/**
 * "What does a bot see?" - the form, the result of the last audit, and the catalogue readiness.
 */
class Audit extends Template
{
    /** @var string */
    protected $_template = 'Magelan_AiVisibility::audit.phtml';

    /** @var array<string, mixed>|null|false */
    private array|null|false $result = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Auditor $auditor,
        private readonly CatalogReadiness $readiness,
        private readonly Session $session,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResult(): ?array
    {
        if ($this->result === false) {
            $result = $this->session->getData('magelan_ai_audit', true);
            $this->result = is_array($result) ? $result : null;
        }

        return $this->result;
    }

    public function getDefaultUrl(): string
    {
        $result = $this->getResult();

        return $result !== null ? (string)$result['input'] : $this->_storeManager->getDefaultStoreView()?->getBaseUrl() ?? '';
    }

    /**
     * @return string[]
     */
    public function getAgents(): array
    {
        return $this->auditor->agents();
    }

    public function getRunUrl(): string
    {
        return $this->getUrl('*/*/run');
    }

    /**
     * @return array<int, array{label: string, count: int, total: int}>
     */
    public function getReadiness(): array
    {
        $store = $this->_storeManager->getDefaultStoreView();

        return $this->readiness->report($store ? (int)$store->getId() : 1);
    }

    public function kindLabel(string $kind): string
    {
        return match ($kind) {
            BotCatalog::TRAINING => (string)__('Model training'),
            BotCatalog::SEARCH => (string)__('AI search index'),
            default => (string)__('Fetches for a user'),
        };
    }
}

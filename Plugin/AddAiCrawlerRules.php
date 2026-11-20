<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Plugin;

use Magelan\AiVisibility\Model\Config;
use Magelan\AiVisibility\Model\RobotsRules;
use Magento\Robots\Model\Robots;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Appends the AI crawler groups to the robots.txt Magento builds from the design configuration.
 * The shop's own lines stay exactly as they are; ours come after them.
 */
class AddAiCrawlerRules
{
    public function __construct(
        private readonly Config $config,
        private readonly RobotsRules $rules,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * $result is null when the shop has never saved custom robots.txt instructions
     * (no design/search_engine_robots/custom_instructions row) - robots.txt then failed with 500.
     */
    public function afterGetData(Robots $subject, ?string $result): string
    {
        $result = (string)$result;
        $storeId = (int)$this->storeManager->getStore()->getId();

        if (!$this->config->isEnabled($storeId) || !$this->config->managesRobots($storeId)) {
            return $result;
        }

        $block = $this->rules->build($result, $storeId);

        if ($this->config->servesLlms($storeId)) {
            $block .= "\n# For AI assistants: " . rtrim($this->storeManager->getStore()->getBaseUrl(), '/') . "/llms.txt\n";
        }

        return rtrim($result) . "\n" . $block;
    }
}

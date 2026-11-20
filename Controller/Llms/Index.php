<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Controller\Llms;

use Magelan\AiVisibility\Model\Config;
use Magelan\AiVisibility\Model\Llms\Builder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * llms.txt and llms-full.txt of the current store view, as Markdown in plain text.
 *
 * Built at most every six hours and kept in the cache: the full file of a large catalogue takes a
 * while, and assistants fetch it often.
 */
class Index implements HttpGetActionInterface
{
    public const CACHE_TAG = 'magelan_ai_llms';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly Builder $builder,
        private readonly Config $config,
        private readonly CacheInterface $cache,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): Raw
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $full = (bool)$this->request->getParam('full');

        if (!$this->config->isEnabled($storeId)
            || !$this->config->servesLlms($storeId)
            || ($full && !$this->config->servesLlmsFull($storeId))
        ) {
            // A plain 404: thrown from here, Magento's NotFoundException would become a 500.
            return $this->rawFactory->create()
                ->setHttpResponseCode(404)
                ->setHeader('Content-Type', 'text/plain; charset=UTF-8', true)
                ->setContents('Not found');
        }

        $key = self::CACHE_TAG . '_' . $storeId . ($full ? '_full' : '');
        $text = $this->cache->load($key);

        if (!$text) {
            $text = $this->builder->build($storeId, $full);
            $this->cache->save($text, $key, [self::CACHE_TAG], 6 * 3600);
        }

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=UTF-8', true);
        $result->setHeader('Cache-Control', 'public, max-age=3600', true);
        // The file is for assistants to read, not a page for search results.
        $result->setHeader('X-Robots-Tag', 'noindex', true);

        return $result->setContents($text);
    }
}

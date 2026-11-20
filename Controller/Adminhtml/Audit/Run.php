<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Controller\Adminhtml\Audit;

use Magelan\AiVisibility\Model\Audit\Auditor;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Audits one address and leaves the result for the page to show.
 *
 * Only addresses of this shop's own store views are fetched: the server makes the request, and
 * an admin form must not become a way to make it request anything else.
 */
class Run extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magelan_AiVisibility::audit';

    public function __construct(
        Context $context,
        private readonly Auditor $auditor,
        private readonly Session $session,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $url = trim((string)$this->getRequest()->getParam('url'));
        $agent = (string)$this->getRequest()->getParam('agent', 'GPTBot');
        $result = ['input' => $url, 'agent' => $agent];

        try {
            $storeId = $this->storeFor($url);
            $result += $this->auditor->audit($url, $agent, $storeId);
        } catch (LocalizedException $e) {
            $result['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $result['error'] = (string)__('The page could not be checked: %1', $e->getMessage());
        }

        $this->session->setData('magelan_ai_audit', $result);

        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }

    /**
     * The store view whose base address the URL starts with.
     *
     * @throws LocalizedException
     */
    private function storeFor(string $url): int
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        foreach ($this->storeManager->getStores() as $store) {
            foreach ([false, true] as $secure) {
                $base = (string)$store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK, $secure);

                if ($host !== '' && strtolower((string)parse_url($base, PHP_URL_HOST)) === $host) {
                    return (int)$store->getId();
                }
            }
        }

        throw new LocalizedException(__('Only addresses of this shop can be checked.'));
    }
}

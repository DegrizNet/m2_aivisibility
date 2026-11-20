<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Controller\Adminhtml\Review;

use Magelan\AiVisibility\Model\Seo\Review;
use Magelan\AiVisibility\Model\Seo\ServerAddress;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "Review now": starts a review of the chosen store view; the open page moves it on.
 */
class Start extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magelan_AiVisibility::review';

    public function __construct(
        Context $context,
        private readonly Review $review,
        private readonly StoreManagerInterface $storeManager,
        private readonly ServerAddress $serverAddress
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $this->serverAddress->remember((string)$this->getRequest()->getServer('SERVER_ADDR'));
        try {
            $storeId = (int)$this->storeManager->getStore((int)$this->getRequest()->getParam('store'))->getId();
            if ($storeId === 0) {
                $storeId = (int)$this->storeManager->getDefaultStoreView()?->getId();
            }
            $this->review->start($storeId);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('The review could not start: %1', $e->getMessage()));
            $storeId = 0;
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/index', $storeId ? ['store' => $storeId] : []);
    }
}

<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Controller\Adminhtml\Review;

use Magelan\AiVisibility\Model\Seo\Missing;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Takes a missing address off the list (a scanner's probe, a page that is gone for good).
 */
class Ignore extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magelan_AiVisibility::redirect';

    public function __construct(Context $context, private readonly Missing $missing)
    {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        try {
            $this->missing->ignore((int)$this->getRequest()->getParam('id'));
            $this->messageManager->addSuccessMessage(__('The address is no longer listed.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/index', ['store' => (int)$this->getRequest()->getParam('store'), '_fragment' => 'missing']);
    }
}

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
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Exception\LocalizedException;

/**
 * One click: a permanent redirect (URL rewrite) from a missing address to an existing page.
 */
class Redirect extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magelan_AiVisibility::redirect';

    public function __construct(Context $context, private readonly Missing $missing)
    {
        parent::__construct($context);
    }

    public function execute(): RedirectResult
    {
        try {
            $target = $this->missing->redirect((int)$this->getRequest()->getParam('id'), (string)$this->getRequest()->getParam('target'));
            $this->messageManager->addSuccessMessage(__('Redirect created: it now leads to /%1 (Marketing > URL Rewrites).', $target));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('The redirect could not be saved: %1', $e->getMessage()));
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/index', ['store' => (int)$this->getRequest()->getParam('store'), '_fragment' => 'missing']);
    }
}

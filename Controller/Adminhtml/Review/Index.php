<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Controller\Adminhtml\Review;

use Magelan\AiVisibility\Model\Seo\ServerAddress;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;

/**
 * Marketing > AI Visibility > Page Review.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magelan_AiVisibility::review';

    public function __construct(Context $context, private readonly ServerAddress $serverAddress)
    {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        // Cron has no web request: the review learns here where the web server listens.
        $this->serverAddress->remember((string)$this->getRequest()->getServer('SERVER_ADDR'));
        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Magelan_AiVisibility::review_page');
        $page->getConfig()->getTitle()->prepend(__('Page Review'));

        return $page;
    }
}

<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Controller\Adminhtml\Review;

use Magelan\AiVisibility\Model\Seo\Review;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;

/**
 * Moves an open review on for about twenty seconds and answers its progress.
 */
class Step extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magelan_AiVisibility::review';

    private const SECONDS = 20;

    public function __construct(Context $context, private readonly Review $review)
    {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $run = $this->review->step((int)$this->getRequest()->getParam('run'), self::SECONDS);
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        return $result->setData([
            'open' => $this->review->isOpen($run ?: null),
            'status' => (string)($run['status'] ?? ''),
            'checked' => (int)($run['checked'] ?? 0),
            'queued' => (int)($run['queued'] ?? 0),
            'text' => ($run['status'] ?? '') === Review::LINKS
                ? (string)__('Checking the links between the pages...')
                : (string)__('%1 of %2 pages checked.', (int)($run['checked'] ?? 0), (int)($run['queued'] ?? 0)),
        ]);
    }
}

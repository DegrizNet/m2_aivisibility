<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Config\Source;

use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Active CMS pages, by identifier - the identifier is the same in every store view, the id is not.
 */
class CmsPages implements OptionSourceInterface
{
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        $seen = [];

        foreach ($this->collectionFactory->create()->addFieldToFilter('is_active', 1)->setOrder('title', 'ASC') as $page) {
            $identifier = (string)$page->getIdentifier();

            if (isset($seen[$identifier]) || $identifier === 'no-route') {
                continue;
            }

            $seen[$identifier] = true;
            $options[] = ['value' => $identifier, 'label' => $page->getTitle() . ' (' . $identifier . ')'];
        }

        return $options;
    }
}

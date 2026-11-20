<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Config\Source;

use Magelan\AiVisibility\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class Policy implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::ALLOW, 'label' => __('Allow')],
            ['value' => Config::BLOCK, 'label' => __('Block')],
        ];
    }
}

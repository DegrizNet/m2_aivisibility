<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Config\Source;

use Magelan\AiVisibility\Model\BotCatalog;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Every known AI crawler, labelled with its company and purpose.
 */
class Bots implements OptionSourceInterface
{
    public function __construct(private readonly BotCatalog $catalog)
    {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $kinds = [
            BotCatalog::TRAINING => (string)__('training'),
            BotCatalog::SEARCH => (string)__('AI search'),
            BotCatalog::USER => (string)__('user request'),
        ];
        $options = [];

        foreach ($this->catalog->all() as $token => $bot) {
            $options[] = ['value' => $token, 'label' => sprintf('%s (%s, %s)', $token, $bot['company'], $kinds[$bot['kind']])];
        }

        return $options;
    }
}

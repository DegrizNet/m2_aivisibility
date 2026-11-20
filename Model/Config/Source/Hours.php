<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * 00:00 to 23:00, for the hour the nightly page review starts.
 */
class Hours implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function toOptionArray(): array
    {
        $out = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $out[] = ['value' => $hour, 'label' => sprintf('%02d:00', $hour)];
        }

        return $out;
    }
}

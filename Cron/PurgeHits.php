<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Cron;

use Magelan\AiVisibility\Model\Config;
use Magelan\AiVisibility\Model\Stats;

/**
 * Nightly: single bot visits older than the retention are dropped; the daily counts stay.
 */
class PurgeHits
{
    public function __construct(
        private readonly Stats $stats,
        private readonly Config $config
    ) {
    }

    public function execute(): void
    {
        $this->stats->purgeHits($this->config->getHitRetentionDays());
    }
}

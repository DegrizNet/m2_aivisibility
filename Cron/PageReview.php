<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Cron;

use Magelan\AiVisibility\Model\Config;
use Magelan\AiVisibility\Model\Seo\Review;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every five minutes: starts the night's page review at the chosen hour (shop time), retries one
 * a CDN stopped an hour later, and works on open reviews for at most two and a half minutes, so
 * one cron run never blocks the next.
 */
class PageReview
{
    private const STEP_SECONDS = 150;

    public function __construct(
        private readonly Review $review,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $deadline = time() + self::STEP_SECONDS;

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            if (!$store->getIsActive() || !$this->config->reviewsPages($storeId)) {
                continue;
            }

            try {
                $run = $this->review->latestRun($storeId);
                if (!$this->review->isOpen($run)) {
                    if (!$this->due($run)) {
                        continue;
                    }
                    $run = $this->review->run($this->review->start($storeId));
                }
                $left = $deadline - time();
                if ($left < 10) {
                    return;
                }
                $this->review->step((int)$run['run_id'], $left);
            } catch (\Throwable $e) {
                $this->logger->error('Magelan AI visibility: page review of store ' . $storeId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Whether tonight's review is due: the start hour has passed and no run started since.
     *
     * @param array<string, mixed>|null $last
     */
    private function due(?array $last): bool
    {
        $now = $this->timezone->date();
        $start = (clone $now)->setTime($this->config->getReviewHour(), 0);
        if ($now < $start) {
            return false;
        }
        if ($last === null) {
            return true;
        }
        // A review a CDN stopped is tried again an hour later: an admin may have opened the page
        // review in between, which tells it the address of the web server.
        if ($last['status'] === Review::BLOCKED && strtotime($last['started_at'] . ' UTC') < time() - 3600) {
            return true;
        }

        return new \DateTime($last['started_at'], new \DateTimeZone('UTC')) < $start;
    }
}

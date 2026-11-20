<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Cron;

use Magelan\AiVisibility\Model\Seo\Missing;
use Magelan\AiVisibility\Model\Seo\WeeklyMail;
use Psr\Log\LoggerInterface;

/**
 * Monday 07:00: the weekly page review e-mail, and missing addresses nobody asked for in 90 days
 * leave the list.
 */
class PageReviewWeekly
{
    public function __construct(
        private readonly WeeklyMail $mail,
        private readonly Missing $missing,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->missing->purge(90);
            $this->mail->send();
        } catch (\Throwable $e) {
            $this->logger->error('Magelan AI visibility: weekly page review: ' . $e->getMessage());
        }
    }
}

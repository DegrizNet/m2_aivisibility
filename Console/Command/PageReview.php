<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Console\Command;

use Magelan\AiVisibility\Model\Seo\Review;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento magelan:ai:page-review --store=code [--limit=200] [--delay=1000]
 *
 * Runs a page review now, to the end, instead of waiting for the night. The review is injected
 * as a proxy (di.xml): every bin/magento call constructs every command.
 */
class PageReview extends Command
{
    public function __construct(
        private readonly Review $review,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('magelan:ai:page-review')
            ->setDescription('Review the pages of a store view now (titles, descriptions, headings, links, redirects)')
            ->addOption('store', 's', InputOption::VALUE_REQUIRED, 'Store view (code or id)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Pages to check (default: the setting)')
            ->addOption('delay', 'd', InputOption::VALUE_REQUIRED, 'Pause between pages in ms (default: the setting)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        } catch (LocalizedException $e) {
            unset($e);
        }

        $code = (string)$input->getOption('store');
        try {
            $store = $code !== ''
                ? $this->storeManager->getStore(ctype_digit($code) ? (int)$code : $code)
                : $this->storeManager->getDefaultStoreView();
        } catch (\Exception $e) {
            $output->writeln('<error>No store view "' . $code . '".</error>');

            return Command::FAILURE;
        }

        $limit = $input->getOption('limit') !== null ? max(1, (int)$input->getOption('limit')) : null;
        $delay = $input->getOption('delay') !== null ? max(0, (int)$input->getOption('delay')) : null;
        $runId = $this->review->start((int)$store->getId(), $limit);
        $output->writeln(sprintf('Run %d for %s', $runId, $store->getCode()));

        do {
            $run = $this->review->step($runId, 30, $delay);
            $output->writeln(sprintf('  %s: %d / %d pages', $run['status'], (int)$run['checked'], (int)$run['queued']));
        } while ($this->review->isOpen($run));

        if ($run['status'] !== Review::DONE) {
            $output->writeln('<error>' . (string)$run['note'] . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Score %d / 100 over %d pages', (int)$run['score'], (int)$run['pages']));

        return Command::SUCCESS;
    }
}

<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Seo;

use Magelan\AiVisibility\Model\Config;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Monday morning: the score of every reviewed store view, how it moved since the week before,
 * the problems with the most pages and the most requested missing addresses.
 */
class WeeklyMail
{
    public const TEMPLATE = 'magelan_ai_review_weekly';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Issues $issues,
        private readonly Missing $missing,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TransportBuilder $transportBuilder,
        private readonly BackendUrl $backendUrl,
        private readonly StoreManagerInterface $storeManager,
        private readonly Escaper $escaper,
        private readonly Json $json,
        private readonly Emulation $emulation
    ) {
    }

    /**
     * @return string[] the addresses it went to (empty: nothing to report or switched off)
     */
    public function send(): array
    {
        if (!$this->config->sendsWeeklyReview()) {
            return [];
        }
        $to = $this->config->getWeeklyRecipients();
        if ($to === []) {
            $general = (string)$this->scopeConfig->getValue('trans_email/ident_general/email');
            $to = filter_var($general, FILTER_VALIDATE_EMAIL) ? [$general] : [];
        }
        $storeId = (int)$this->storeManager->getDefaultStoreView()?->getId();
        // Cron has no locale: the lines are written in the language of the default store view.
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $sections = $this->sections();
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        if ($to === [] || $sections === '') {
            return [];
        }

        $this->backendUrl->turnOffSecretKey();
        $url = $this->backendUrl->getUrl('magelan_ai/review/index');

        $transport = $this->transportBuilder
            ->setTemplateIdentifier(self::TEMPLATE)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
            ->setTemplateVars([
                'shop' => (string)$this->storeManager->getStore($storeId)->getFrontendName(),
                'sections' => $sections,
                'url' => $url,
            ])
            ->setFromByScope('general', $storeId);
        foreach ($to as $email) {
            $transport->addTo($email);
        }
        $transport->getTransport()->sendMessage();

        return $to;
    }

    /**
     * The mail body per store view, already escaped HTML.
     */
    public function sections(): string
    {
        $connection = $this->resource->getConnection();
        $html = '';

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            $runs = $connection->fetchAll($connection->select()
                ->from($this->resource->getTableName('magelan_ai_seo_run'))
                ->where('store_id = ?', $storeId)
                ->where('status = ?', Review::DONE)
                ->order('run_id DESC')
                ->limit(8));
            if ($runs === []) {
                continue;
            }
            $latest = $runs[0];
            $before = null;
            foreach ($runs as $run) {
                if (strtotime($run['finished_at'] . ' UTC') <= strtotime($latest['finished_at'] . ' UTC') - 6 * 86400) {
                    $before = $run;
                    break;
                }
            }

            $score = (int)$latest['score'];
            $move = $before !== null ? $score - (int)$before['score'] : null;
            $color = $score >= 80 ? '#2c6e49' : ($score >= 60 ? '#9a6b00' : '#b3261e');
            $html .= '<tr><td style="padding:16px 0 4px;border-top:1px solid #ece8e1">'
                . '<b style="font-size:15px">' . $this->escaper->escapeHtml($store->getName()) . '</b> '
                . '<span style="font-size:22px;font-weight:bold;color:' . $color . '">' . $score . '</span>'
                . '<span style="color:#57524b"> / 100</span>'
                . ($move !== null && $move !== 0
                    ? ' <span style="color:' . ($move > 0 ? '#2c6e49' : '#b3261e') . '">' . ($move > 0 ? '+' : '') . $move . '</span>'
                    : '')
                . '<br><span style="color:#57524b;font-size:13px">' . $this->escaper->escapeHtml((string)__('%1 pages reviewed', (int)$latest['pages'])) . '</span>'
                . '</td></tr>';

            $summary = (array)$this->json->unserialize((string)($latest['summary'] ?: '{}'));
            $lines = '';
            foreach (array_slice($summary, 0, 5, true) as $code => $count) {
                $lines .= '<li>' . $this->escaper->escapeHtml($this->issues->label((string)$code)) . ': <b>' . (int)$count . '</b></li>';
            }
            $missing = $this->missing->rows($storeId, Missing::OPEN, 3);
            foreach ($missing as $row) {
                $text = (int)$row['bot_hits'] > 0
                    ? __('Not found: /%1 (%2 visits, of them %3 from bots and AI assistants)', $row['path'], (int)$row['hits'] + (int)$row['bot_hits'], (int)$row['bot_hits'])
                    : __('Not found: /%1 (%2 visits)', $row['path'], (int)$row['hits']);
                $lines .= '<li>' . $this->escaper->escapeHtml((string)$text) . '</li>';
            }
            if ($lines !== '') {
                $html .= '<tr><td style="padding:0 0 8px"><ul style="margin:6px 0 0;padding-left:18px;color:#24211e;font-size:13.5px;line-height:1.6">' . $lines . '</ul></td></tr>';
            }
        }

        return $html;
    }
}

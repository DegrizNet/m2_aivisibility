<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Audit;

use Magelan\AiVisibility\Model\BotCatalog;
use Magelan\AiVisibility\Model\Config;
use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\LocalizedException;
use Magento\Robots\Model\Robots;
use Magento\Store\Model\App\Emulation;

/**
 * One audit of one address: what the bot gets, what is in it, and whether robots.txt lets each AI
 * crawler read it.
 */
class Auditor
{
    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly PageAnalyzer $analyzer,
        private readonly RobotsEvaluator $robotsEvaluator,
        private readonly BotCatalog $catalog,
        private readonly Robots $robots,
        private readonly Emulation $emulation,
        private readonly Config $config,
        private readonly HttpRequest $request
    ) {
    }

    /**
     * The address this admin request reached the web server on - the same server, and the
     * address its virtual host for the shop listens on.
     */
    private function serverAddress(): string
    {
        $address = (string)$this->request->getServer('SERVER_ADDR');

        return filter_var($address, FILTER_VALIDATE_IP) ? $address : '127.0.0.1';
    }

    /**
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function audit(string $url, string $agent, int $storeId): array
    {
        $response = $this->fetcher->fetch($url, $agent);
        $notice = null;

        // A CDN that challenges this server is not necessarily challenging the real crawler, which
        // comes from its own addresses. Say so, and read the page from Magento directly instead.
        if ($this->fetcher->isChallenge($response)) {
            $notice = (string)__('The CDN in front of the shop (HTTP %1) answered this server with a bot challenge instead of the page. Real AI crawlers come from their own addresses and may be let through or stopped - check the bot settings of the CDN. The page below was read from Magento directly.', $response['status']);

            try {
                $response = $this->fetcher->fetch($url, $agent, $this->serverAddress());
            } catch (LocalizedException) {
                $notice = (string)__('The CDN in front of the shop (HTTP %1) answered this server with a bot challenge, and the page could not be read from Magento directly either.', $response['status']);
            }
        }

        $result = $this->analyzer->analyze($response['body'], $response['status'], $response['headers'], $response['url']);
        $result['notice'] = $notice;
        $result['fetched'] = $response;
        unset($result['fetched']['body']);
        $result['robots'] = $this->robotsAccess((string)parse_url($response['url'], PHP_URL_PATH) ?: '/', $storeId);

        return $result;
    }

    /**
     * Per bot: may it read this path, according to the robots.txt this store view serves.
     *
     * @return array<string, array{kind: string, allowed: bool}>
     */
    public function robotsAccess(string $path, int $storeId): array
    {
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

        try {
            $robots = (string)$this->robots->getData();
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        $out = [];

        foreach ($this->catalog->all() as $token => $bot) {
            $out[$token] = [
                'kind' => $bot['kind'],
                'allowed' => $this->robotsEvaluator->isAllowed($robots, $token, $path),
            ];
        }

        return $out;
    }

    /**
     * @return string[]
     */
    public function agents(): array
    {
        return $this->fetcher->agents();
    }

    public function servesLlms(int $storeId): bool
    {
        return $this->config->isEnabled($storeId) && $this->config->servesLlms($storeId);
    }
}

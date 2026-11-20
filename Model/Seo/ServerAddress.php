<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Seo;

use Magento\Framework\FlagManager;

/**
 * The address the shop's web server listens on, as an admin request saw it. A CDN that stops
 * requests from the server itself (a bot challenge) is passed by asking that address directly,
 * with the shop's host name, so the virtual host and the certificate stay the shop's own.
 *
 * Cron and the command line have no web request, so the address is remembered from the admin
 * pages; whether the CDN has to be passed is remembered per store view.
 */
class ServerAddress
{
    private const ADDRESS = 'magelan_ai_server_address';
    private const DIRECT = 'magelan_ai_review_direct';

    public function __construct(private readonly FlagManager $flags)
    {
    }

    public function remember(string $address): void
    {
        if (filter_var($address, FILTER_VALIDATE_IP) && $address !== $this->get()) {
            $this->flags->saveFlag(self::ADDRESS, $address);
        }
    }

    public function get(): ?string
    {
        $address = (string)$this->flags->getFlagData(self::ADDRESS);

        return filter_var($address, FILTER_VALIDATE_IP) ? $address : null;
    }

    public function readsDirectly(int $storeId): bool
    {
        return in_array($storeId, (array)$this->flags->getFlagData(self::DIRECT), true);
    }

    public function setReadsDirectly(int $storeId, bool $direct): void
    {
        $stores = array_values(array_diff((array)$this->flags->getFlagData(self::DIRECT), [$storeId]));
        if ($direct) {
            $stores[] = $storeId;
        }
        $this->flags->saveFlag(self::DIRECT, $stores);
    }
}

<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Plugin;

use Magelan\AiVisibility\Model\Audit\Fetcher;
use Magento\Framework\App\PageCache\Kernel;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * The page review and the audit read pages the built-in full page cache already holds, but never
 * write to it: the nightly review reads every page of every store view, and on a file cache the
 * copies it left (a product page can be close to a megabyte) are never removed - 5.8 GB in eleven
 * days on a live shop. The response still loses its public cache headers, as Magento does.
 */
class SkipPageCacheForAudit
{
    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    public function aroundProcess(Kernel $subject, callable $proceed, HttpResponse $response): void
    {
        if ($this->request instanceof HttpRequest && $this->request->getHeader(Fetcher::AUDIT_HEADER)) {
            $response->setNoCacheHeaders();
            return;
        }

        $proceed($response);
    }
}

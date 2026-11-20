<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Audit;

/**
 * Reads a page the way an AI crawler does - the HTML as the server sent it, no JavaScript run -
 * and lists what it finds and what it misses.
 *
 * Most AI crawlers do not run scripts. Whatever a theme builds in the browser - prices loaded by
 * AJAX, reviews from a widget on another domain, text in a JS slider - simply is not there for them.
 */
class PageAnalyzer
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /**
     * @param array<string, string> $headers lower-case names
     * @return array{facts: array<string, mixed>, checks: array<int, array{status: string, label: string, detail: string}>, score: int}
     */
    public function analyze(string $html, int $status, array $headers, string $url): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);

        $title = trim((string)$this->first($xpath, '//title'));
        $description = trim((string)$this->attr($xpath, '//meta[translate(@name,"DESCRIPTION","description")="description"]', 'content'));
        $canonical = trim((string)$this->attr($xpath, '//link[@rel="canonical"]', 'href'));
        $robotsMeta = strtolower(trim((string)$this->attr($xpath, '//meta[translate(@name,"ROBTS","robts")="robots"]', 'content')));
        $robotsHeader = strtolower((string)($headers['x-robots-tag'] ?? ''));
        $h1 = array_values(array_filter(array_map('trim', $this->all($xpath, '//h1'))));
        $types = [];
        $product = [];

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $data = json_decode((string)$node->textContent, true);

            if (is_array($data)) {
                $this->collectTypes($data, $types, $product);
            }
        }

        $this->collectMicrodata($xpath, $types, $product);

        $text =$this->visibleText($dom);
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
        $images = $xpath->query('//img');
        $imageCount = $images === false ? 0 : $images->length;
        $withoutAlt = 0;

        foreach ($images ?: [] as $image) {
            /** @var \DOMElement $image */
            if (trim($image->getAttribute('alt')) === '') {
                $withoutAlt++;
            }
        }

        $facts = [
            'status' => $status,
            'bytes' => strlen($html),
            'title' => $title,
            'description' => $description,
            'h1' => $h1,
            'canonical' => $canonical,
            'robots' => trim($robotsMeta . ' ' . $robotsHeader),
            'types' => array_values(array_unique($types)),
            'words' => $words,
            'images' => $imageCount,
            'images_without_alt' => $withoutAlt,
            'price' => $product['price'] ?? null,
            'availability' => $product['availability'] ?? null,
            'rating' => $product['rating'] ?? null,
            'reviews' => $product['reviews'] ?? 0,
            'links' => $this->internalLinks($xpath, $url),
        ];

        $checks = $this->checks($facts, $url);
        $weights = [self::OK => 1.0, self::WARN => 0.5, self::FAIL => 0.0];
        $score = $checks === [] ? 0 : (int)round(100 * array_sum(array_map(
            static fn (array $check): float => $weights[$check['status']],
            $checks
        )) / count($checks));

        return ['facts' => $facts, 'checks' => $checks, 'score' => $score];
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<int, array{status: string, label: string, detail: string}>
     */
    private function checks(array $facts, string $url): array
    {
        $checks = [];
        $add = static function (string $status, string $label, string $detail = '') use (&$checks): void {
            $checks[] = ['status' => $status, 'label' => $label, 'detail' => $detail];
        };

        $add(
            $facts['status'] === 200 ? self::OK : self::FAIL,
            (string)__('The page answers the bot'),
            (string)__('HTTP status %1', $facts['status'])
        );

        $noindex = str_contains($facts['robots'], 'noindex') || str_contains($facts['robots'], 'none');
        $add($noindex ? self::FAIL : self::OK, (string)__('The page may be indexed'), $facts['robots']);

        $add(
            $facts['title'] !== '' ? self::OK : self::FAIL,
            (string)__('Title'),
            $facts['title']
        );

        $length = mb_strlen($facts['description']);
        $add(
            $length >= 50 ? self::OK : ($length > 0 ? self::WARN : self::FAIL),
            (string)__('Meta description'),
            $length > 0 ? $facts['description'] : (string)__('Missing. Assistants often quote it as the summary of the page.')
        );

        $add(
            count($facts['h1']) === 1 ? self::OK : self::WARN,
            (string)__('One main heading (H1)'),
            $facts['h1'] === [] ? (string)__('None found.') : implode(' | ', $facts['h1'])
        );

        $add(
            $facts['words'] >= 150 ? self::OK : ($facts['words'] >= 50 ? self::WARN : self::FAIL),
            (string)__('Text readable without JavaScript'),
            $facts['words'] >= 150
                ? (string)__('%1 words.', $facts['words'])
                : (string)__('%1 words. Below 150 the page says little to a bot that does not run scripts.', $facts['words'])
        );

        if ($facts['canonical'] !== '') {
            $same = rtrim(strtok($facts['canonical'], '?#') ?: '', '/') === rtrim(strtok($url, '?#') ?: '', '/');
            $add($same ? self::OK : self::WARN, (string)__('Canonical address'), $facts['canonical']);
        } else {
            $add(self::WARN, (string)__('Canonical address'), (string)__('Missing.'));
        }

        $add(
            $facts['types'] !== [] ? self::OK : self::FAIL,
            (string)__('Structured data (JSON-LD)'),
            $facts['types'] !== [] ? implode(', ', $facts['types']) : (string)__('None. Structured data is the most reliable way to state facts to a machine.')
        );

        if (in_array('Product', $facts['types'], true)) {
            $add(
                $facts['price'] !== null ? self::OK : self::FAIL,
                (string)__('Price in the structured data'),
                $facts['price'] !== null ? (string)$facts['price'] : (string)__('Missing. Without it an assistant can not answer "how much is it".')
            );
            $add(
                $facts['availability'] !== null ? self::OK : self::WARN,
                (string)__('Availability in the structured data'),
                (string)$facts['availability']
            );
            $add(
                $facts['rating'] !== null ? self::OK : self::WARN,
                (string)__('Rating in the structured data'),
                $facts['rating'] !== null
                    ? (string)__('%1 from %2 reviews', $facts['rating'], $facts['reviews'])
                    : (string)__('No reviews in the markup. Assistants favour products with visible ratings.')
            );
        }

        if (in_array('BreadcrumbList', $facts['types'], true) === false) {
            $add(self::WARN, (string)__('Breadcrumbs in the structured data'), (string)__('Missing.'));
        } else {
            $add(self::OK, (string)__('Breadcrumbs in the structured data'));
        }

        if ($facts['images'] > 0) {
            $add(
                $facts['images_without_alt'] === 0 ? self::OK : ($facts['images_without_alt'] * 2 <= $facts['images'] ? self::WARN : self::FAIL),
                (string)__('Images described (alt text)'),
                (string)__('%1 of %2 images have no alt text.', $facts['images_without_alt'], $facts['images'])
            );
        }

        return $checks;
    }

    /**
     * Product facts in schema.org microdata (itemprop), which Luma and Hyvä print on the product
     * page. Search engines and assistants read it as well as JSON-LD, so a price that is only in
     * microdata is not missing. JSON-LD values found earlier win.
     *
     * @param string[] $types
     * @param array<string, mixed> $product
     */
    private function collectMicrodata(\DOMXPath $xpath, array &$types, array &$product): void
    {
        $scopes = $xpath->query('//*[@itemscope][contains(@itemtype,"schema.org/Product")]');

        foreach ($scopes ?: [] as $scope) {
            $types[] = 'Product';
            $offer = $xpath->query('.//*[@itemprop="offers"]', $scope)?->item(0) ?? $scope;
            $price = $this->itemprop($xpath, $offer, 'price') ?? $this->itemprop($xpath, $offer, 'lowPrice');

            if (!isset($product['price']) && $price !== null) {
                $product['price'] = trim($price . ' ' . ($this->itemprop($xpath, $offer, 'priceCurrency') ?? ''));
            }

            $availability = $this->itemprop($xpath, $offer, 'availability');

            if (!isset($product['availability']) && $availability !== null) {
                $product['availability'] = (string)preg_replace('~^https?://schema.org/~', '', $availability);
            }

            $rating = $xpath->query('.//*[@itemprop="aggregateRating"]', $scope)?->item(0);

            if (!isset($product['rating']) && $rating !== null) {
                $product['rating'] = $this->itemprop($xpath, $rating, 'ratingValue');
                $product['reviews'] = (int)($this->itemprop($xpath, $rating, 'reviewCount') ?? $this->itemprop($xpath, $rating, 'ratingCount') ?? 0);
            }
        }
    }

    private function itemprop(\DOMXPath $xpath, \DOMNode $context, string $name): ?string
    {
        $node = $xpath->query('.//*[@itemprop="' . $name . '"]', $context)?->item(0);

        if (!$node instanceof \DOMElement) {
            return null;
        }

        foreach (['content', 'href', 'value'] as $attribute) {
            if ($node->hasAttribute($attribute)) {
                $value = trim(html_entity_decode($node->getAttribute($attribute), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                return $value === '' ? null : $value;
            }
        }

        $value = trim((string)$node->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<mixed> $data
     * @param string[] $types
     * @param array<string, mixed> $product
     */
    private function collectTypes(array $data, array &$types, array &$product): void
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                if (is_array($node)) {
                    $this->collectTypes($node, $types, $product);
                }
            }
        }

        if (array_is_list($data)) {
            foreach ($data as $node) {
                if (is_array($node)) {
                    $this->collectTypes($node, $types, $product);
                }
            }

            return;
        }

        foreach ((array)($data['@type'] ?? []) as $type) {
            if (is_string($type)) {
                $types[] = $type;
            }
        }

        if (in_array('Product', (array)($data['@type'] ?? []), true)) {
            $offers = $data['offers'] ?? null;
            $offer = is_array($offers) && array_is_list($offers) ? ($offers[0] ?? null) : $offers;

            if (is_array($offer)) {
                $price = $offer['price'] ?? $offer['lowPrice'] ?? null;

                if ($price !== null && $price !== '') {
                    $product['price'] = trim($price . ' ' . ($offer['priceCurrency'] ?? ''));
                }

                if (!empty($offer['availability'])) {
                    $product['availability'] = (string)preg_replace('~^https?://schema.org/~', '', (string)$offer['availability']);
                }
            }

            if (is_array($data['aggregateRating'] ?? null)) {
                $product['rating'] = $data['aggregateRating']['ratingValue'] ?? null;
                $product['reviews'] = (int)($data['aggregateRating']['reviewCount'] ?? $data['aggregateRating']['ratingCount'] ?? 0);
            }
        }
    }

    /**
     * Paths of the links to this host (no query strings, fragments, mail or script links), for the
     * page review's link check.
     *
     * @return string[]
     */
    private function internalLinks(\DOMXPath $xpath, string $url): array
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $scheme = (string)(parse_url($url, PHP_URL_SCHEME) ?: 'https');
        $out = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            /** @var \DOMElement $node */
            $href = trim($node->getAttribute('href'));

            if ($href === '' || $href[0] === '#' || preg_match('~^(mailto|tel|javascript|data|sms):~i', $href)) {
                continue;
            }

            if (str_starts_with($href, '//')) {
                $href = $scheme . ':' . $href;
            }

            if (preg_match('~^https?://~i', $href)) {
                if (strtolower((string)parse_url($href, PHP_URL_HOST)) !== $host) {
                    continue;
                }
                $path = (string)parse_url($href, PHP_URL_PATH);
            } elseif ($href[0] === '/') {
                $path = (string)parse_url($href, PHP_URL_PATH);
            } else {
                continue;
            }

            // /cdn-cgi/ is served by the CDN (Cloudflare e-mail protection and the like), not by the shop.
            if ($path !== '' && !str_starts_with($path, '/cdn-cgi/') && !str_contains($href, '?')) {
                $out[$path] = true;
            }

            if (count($out) >= 400) {
                break;
            }
        }

        return array_keys($out);
    }

    private function visibleText(\DOMDocument $dom): string
    {
        $body = $dom->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        $clone = $body->cloneNode(true);

        foreach (['script', 'style', 'noscript', 'template', 'svg'] as $tag) {
            /** @var \DOMElement $clone */
            $nodes = iterator_to_array($clone->getElementsByTagName($tag));

            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return trim((string)preg_replace('/\s+/u', ' ', $clone->textContent));
    }

    private function first(\DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)?->item(0);

        return $node?->textContent;
    }

    private function attr(\DOMXPath $xpath, string $query, string $attribute): ?string
    {
        $node = $xpath->query($query)?->item(0);

        return $node instanceof \DOMElement ? $node->getAttribute($attribute) : null;
    }

    /**
     * @return string[]
     */
    private function all(\DOMXPath $xpath, string $query): array
    {
        $out = [];

        foreach ($xpath->query($query) ?: [] as $node) {
            $out[] = (string)preg_replace('/\s+/u', ' ', $node->textContent);
        }

        return $out;
    }
}

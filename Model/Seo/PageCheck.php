<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Seo;

/**
 * The problems of one page, from what the page reader found. Problems that need the other pages
 * (duplicates, links) are added by the review at the end of a run.
 */
class PageCheck
{
    public const TITLE_MAX = 65;
    public const TITLE_MIN = 20;
    public const DESC_MIN = 70;
    public const DESC_MAX = 170;
    public const WORDS_MIN = 150;
    public const SLOW_MS = 3000;

    /**
     * @param array<string, mixed> $facts PageAnalyzer facts
     * @param array{first_status: int, status: int, hops: int, url: string, ms: int} $fetched
     * @param string $requested the address that was asked for
     * @param string $entityType product, category, cms-page, home
     * @return array<string, string> code => detail
     */
    public function issues(array $facts, array $fetched, string $requested, string $entityType): array
    {
        $issues = [];

        if ($fetched['hops'] > 0) {
            $issues['redirect'] = (string)__('HTTP %1 to %2', $fetched['first_status'], $fetched['url'])
                . ($fetched['hops'] > 1 ? ' ' . (string)__('(%1 redirects in a row)', $fetched['hops']) : '');
        }

        if ($fetched['status'] >= 400 || $fetched['status'] === 0) {
            $issues['error'] = (string)__('HTTP %1', $fetched['status']);

            return $issues;
        }

        $robots = (string)($facts['robots'] ?? '');
        if (str_contains($robots, 'noindex') || preg_match('/(^|[\s,])none([\s,]|$)/', $robots)) {
            $issues['noindex'] = $robots;
        }

        $title = trim((string)($facts['title'] ?? ''));
        $length = mb_strlen($title);
        if ($title === '') {
            $issues['title_missing'] = '';
        } elseif ($length > self::TITLE_MAX) {
            $issues['title_long'] = (string)__('%1 characters', $length);
        } elseif ($length < self::TITLE_MIN) {
            $issues['title_short'] = (string)__('%1 characters', $length);
        }

        $description = trim((string)($facts['description'] ?? ''));
        $length = mb_strlen($description);
        if ($description === '') {
            $issues['desc_missing'] = '';
        } elseif ($length < self::DESC_MIN) {
            $issues['desc_short'] = (string)__('%1 characters', $length);
        } elseif ($length > self::DESC_MAX) {
            $issues['desc_long'] = (string)__('%1 characters', $length);
        }

        $h1 = (array)($facts['h1'] ?? []);
        if ($h1 === []) {
            $issues['h1_missing'] = '';
        } elseif (count($h1) > 1) {
            $issues['h1_many'] = implode(' | ', array_slice($h1, 0, 4));
        }

        $canonical = trim((string)($facts['canonical'] ?? ''));
        if ($canonical !== '' && $this->normalise($canonical) !== $this->normalise($fetched['url'])
            && $this->normalise($canonical) !== $this->normalise($requested)
        ) {
            $issues['canonical_other'] = $canonical;
        }

        if ($entityType !== 'home' && (int)($facts['words'] ?? 0) < self::WORDS_MIN) {
            $issues['thin'] = (string)__('%1 words', (int)($facts['words'] ?? 0));
        }

        if ((int)($facts['images_without_alt'] ?? 0) > 0) {
            $issues['alt_missing'] = (string)__('%1 of %2 images', (int)$facts['images_without_alt'], (int)($facts['images'] ?? 0));
        }

        if ($entityType === 'product' && ($facts['price'] ?? null) === null) {
            $issues['product_data'] = in_array('Product', (array)($facts['types'] ?? []), true)
                ? (string)__('Product data without a price')
                : (string)__('No Product data');
        }

        if ($fetched['ms'] > self::SLOW_MS) {
            $issues['slow'] = (string)__('%1 s', number_format($fetched['ms'] / 1000, 1));
        }

        return $issues;
    }

    private function normalise(string $url): string
    {
        $url = strtok($url, '?#') ?: '';

        return rtrim(strtolower((string)preg_replace('~^https?://~i', '', $url)), '/');
    }
}

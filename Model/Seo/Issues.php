<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Seo;

/**
 * Every problem the page review can find: how much it weighs, what it is called and what to do
 * about it. A page starts at 100 points and loses the weight of each problem it has.
 */
class Issues
{
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    private const WEIGHTS = [self::HIGH => 25, self::MEDIUM => 10, self::LOW => 3];

    /** code => severity, in the order the review lists them */
    private const SEVERITY = [
        'error' => self::HIGH,
        'noindex' => self::HIGH,
        'title_missing' => self::HIGH,
        'redirect' => self::MEDIUM,
        'links_broken' => self::MEDIUM,
        'title_duplicate' => self::MEDIUM,
        'desc_missing' => self::MEDIUM,
        'desc_duplicate' => self::MEDIUM,
        'h1_missing' => self::MEDIUM,
        'product_data' => self::MEDIUM,
        'title_long' => self::LOW,
        'title_short' => self::LOW,
        'desc_short' => self::LOW,
        'desc_long' => self::LOW,
        'h1_many' => self::LOW,
        'canonical_other' => self::LOW,
        'thin' => self::LOW,
        'alt_missing' => self::LOW,
        'slow' => self::LOW,
        'links_redirect' => self::LOW,
    ];

    /**
     * @return string[] all codes, most serious first
     */
    public function codes(): array
    {
        return array_keys(self::SEVERITY);
    }

    public function severity(string $code): string
    {
        return self::SEVERITY[$code] ?? self::LOW;
    }

    public function weight(string $code): int
    {
        return self::WEIGHTS[$this->severity($code)];
    }

    /**
     * @param array<string, mixed> $issues code => detail
     */
    public function score(array $issues): int
    {
        $lost = 0;

        foreach (array_keys($issues) as $code) {
            $lost += $this->weight((string)$code);
        }

        return max(0, 100 - $lost);
    }

    public function label(string $code): string
    {
        return (string)match ($code) {
            'error' => __('The page does not open'),
            'noindex' => __('Hidden from search engines (noindex)'),
            'title_missing' => __('No title'),
            'redirect' => __('The address redirects'),
            'links_broken' => __('Links to pages that do not exist'),
            'title_duplicate' => __('Same title as other pages'),
            'desc_missing' => __('No meta description'),
            'desc_duplicate' => __('Same meta description as other pages'),
            'h1_missing' => __('No main heading (H1)'),
            'product_data' => __('Product without price in the structured data'),
            'title_long' => __('Title too long'),
            'title_short' => __('Title too short'),
            'desc_short' => __('Meta description too short'),
            'desc_long' => __('Meta description too long'),
            'h1_many' => __('More than one main heading (H1)'),
            'canonical_other' => __('Canonical address points elsewhere'),
            'thin' => __('Little text'),
            'alt_missing' => __('Images without alt text'),
            'slow' => __('Slow answer'),
            'links_redirect' => __('Links to addresses that redirect'),
            default => $code,
        };
    }

    /**
     * What to do, in one or two sentences a shop owner can follow.
     */
    public function advice(string $code): string
    {
        return (string)match ($code) {
            'error' => __('The shop lists this page, but it answers with an error. Check that the product, category or page is enabled and visible in this store view, or remove it.'),
            'noindex' => __('Search engines are told not to show this page. If it should be found, set Meta Robots to "INDEX, FOLLOW" (design settings of the page or Content > Design > Configuration).'),
            'title_missing' => __('Fill in the Meta Title in the "Search Engine Optimization" section. It is the blue line people click in Google.'),
            'redirect' => __('The address the shop links to sends visitors on to another one. Usually an old URL key: save the item again or update the links to the new address.'),
            'links_broken' => __('The page links to addresses that answer 404. Fix or remove these links; the list is below.'),
            'title_duplicate' => __('Several pages have the same title, so search engines cannot tell them apart. Add what makes this page different (colour, size, category).'),
            'desc_missing' => __('Fill in the Meta Description: one or two sentences (70 to 160 characters) that make people click. Without it Google takes a random piece of the page.'),
            'desc_duplicate' => __('Several pages have the same meta description. Write one for this page, or leave it empty so Google picks text from the page.'),
            'h1_missing' => __('The page has no main heading. The theme should show the product, category or page name as H1.'),
            'product_data' => __('Search engines and AI assistants read the price from the structured data (JSON-LD). Check the theme or the rich snippets module.'),
            'title_long' => __('Google shows about 60 characters. Put the important words first and shorten the rest.'),
            'title_short' => __('A title of a few words says little. Add the kind of product, the brand or the category.'),
            'desc_short' => __('Write at least 70 characters: what it is, for whom, and why buy here (delivery, warranty).'),
            'desc_long' => __('Google cuts the description at about 160 characters. Keep the important part at the start.'),
            'h1_many' => __('One main heading per page is clearest. Change the other H1 headings in the content to H2.'),
            'canonical_other' => __('The page tells search engines that another address is the original. Fine for variants and filters; for a normal page check the URL settings.'),
            'thin' => __('Fewer than 150 words of text. Add a description: what it is, materials, sizes, use, care.'),
            'alt_missing' => __('Describe the images in a few words (alt text, "Label" in the product images). Search engines and screen readers read it.'),
            'slow' => __('The page took more than 3 seconds to build. Check that the full page cache is on; if it is, the page may be heavy.'),
            'links_redirect' => __('The page links to old addresses that redirect. Each detour costs time; link to the new address directly.'),
            default => '',
        };
    }
}

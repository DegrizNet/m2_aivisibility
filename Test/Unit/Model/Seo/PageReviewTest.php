<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Test\Unit\Model\Seo;

use Magelan\AiVisibility\Model\Audit\PageAnalyzer;
use Magelan\AiVisibility\Model\Seo\Issues;
use Magelan\AiVisibility\Model\Seo\Missing;
use Magelan\AiVisibility\Model\Seo\PageCheck;
use Magelan\AiVisibility\Model\Seo\Review;
use PHPUnit\Framework\TestCase;

/**
 * The page review's own rules: what counts as a problem on a page, the score, which links and
 * which 404 paths are followed.
 */
class PageReviewTest extends TestCase
{
    private const FETCHED = ['first_status' => 200, 'status' => 200, 'hops' => 0, 'url' => 'https://shop.example/si/cora.html', 'ms' => 400];

    public function testAGoodPageHasNoProblems(): void
    {
        $facts = [
            'title' => 'Hlače Cora iz organskega bombaža | Shop',
            'description' => 'Udobne hlače iz organskega bombaža, v petih barvah, z dostavo v dveh dneh in brezplačno menjavo.',
            'h1' => ['Hlače Cora'],
            'canonical' => 'https://shop.example/si/cora.html',
            'robots' => 'index,follow',
            'words' => 400,
            'images' => 3,
            'images_without_alt' => 0,
            'types' => ['Product'],
            'price' => '49.90 EUR',
        ];

        $issues = (new PageCheck())->issues($facts, self::FETCHED, 'https://shop.example/si/cora.html', 'product');

        self::assertSame([], $issues);
        self::assertSame(100, (new Issues())->score($issues));
    }

    public function testAPoorPageListsItsProblems(): void
    {
        $facts = [
            'title' => 'Cora',
            'description' => '',
            'h1' => ['Cora', 'Novo'],
            'canonical' => 'https://shop.example/si/druga.html',
            'robots' => 'noindex,follow',
            'words' => 40,
            'images' => 4,
            'images_without_alt' => 3,
            'types' => [],
            'price' => null,
        ];
        $fetched = ['first_status' => 301, 'status' => 200, 'hops' => 2, 'url' => 'https://shop.example/si/cora-nova.html', 'ms' => 4200];

        $issues = (new PageCheck())->issues($facts, $fetched, 'https://shop.example/si/cora.html', 'product');

        foreach (['redirect', 'noindex', 'title_short', 'desc_missing', 'h1_many', 'canonical_other', 'thin', 'alt_missing', 'product_data', 'slow'] as $code) {
            self::assertArrayHasKey($code, $issues, $code);
        }
        self::assertStringContainsString('2', $issues['redirect']);
        // 25 (noindex) + 10 (redirect) + 10 (description) + 10 (product data) + 6 x 3
        self::assertSame(100 - 25 - 10 - 10 - 10 - 18, (new Issues())->score($issues));
    }

    public function testAnErrorPageIsOnlyAnError(): void
    {
        $issues = (new PageCheck())->issues([], ['first_status' => 404, 'status' => 404, 'hops' => 0, 'url' => 'x', 'ms' => 100], 'x', 'category');

        self::assertSame(['error'], array_keys($issues));
        self::assertSame(75, (new Issues())->score($issues));
    }

    public function testTheHomePageIsNotThin(): void
    {
        $facts = ['title' => 'Trgovina z oblačili iz bombaža', 'description' => str_repeat('a', 100), 'h1' => ['Shop'], 'words' => 30];

        self::assertArrayNotHasKey('thin', (new PageCheck())->issues($facts, self::FETCHED, 'https://shop.example/si/', 'home'));
    }

    public function testEveryIssueHasALabelAndAdvice(): void
    {
        $issues = new Issues();
        foreach ($issues->codes() as $code) {
            self::assertNotSame($code, $issues->label($code), $code);
            self::assertNotSame('', $issues->advice($code), $code);
        }
    }

    public function testTheAnalyzerCollectsInternalLinks(): void
    {
        $html = '<html><head><title>x</title></head><body>'
            . '<a href="/si/a.html">a</a><a href="https://shop.example/si/b.html#top">b</a><a href="//shop.example/si/c.html">c</a>'
            . '<a href="https://other.example/x.html">x</a><a href="/si/search?q=1">s</a><a href="mailto:a@b.c">m</a>'
            . '<a href="#top">t</a><a href="relative.html">r</a><a href="/si/a.html">again</a></body></html>';

        $links = (new PageAnalyzer())->analyze($html, 200, [], 'https://shop.example/si/')['facts']['links'];

        self::assertSame(['/si/a.html', '/si/b.html', '/si/c.html'], $links);
    }

    public function testTheAnalyzerIgnoresCdnPaths(): void
    {
        $html = '<html><head><title>x</title></head><body>'
            . '<a href="/cdn-cgi/l/email-protection#abc">mail</a>'
            . '<a href="https://shop.example/cdn-cgi/anything">cdn</a><a href="/si/a.html">a</a></body></html>';

        $links = (new PageAnalyzer())->analyze($html, 200, [], 'https://shop.example/si/')['facts']['links'];

        self::assertSame(['/si/a.html'], $links);
    }

    public function testLinksAreMadeRelativeToTheStoreView(): void
    {
        $review = (new \ReflectionClass(Review::class))->newInstanceWithoutConstructor();

        self::assertSame(
            ['a.html', 'kategorija/b.html', ''],
            $review->relativeLinks(['/si/a.html', '/si/kategorija/b.html', '/en/a.html', '/si/'], 'https://shop.example/si/')
        );
        self::assertSame(['a.html'], $review->relativeLinks(['/a.html'], 'https://shop.example/'));
    }

    public function testOnlyPageAddressesAreLoggedAsMissing(): void
    {
        $missing = (new \ReflectionClass(Missing::class))->newInstanceWithoutConstructor();

        self::assertTrue($missing->isPage('stara-majica.html'));
        self::assertTrue($missing->isPage('zenske/majice'));
        foreach (['', 'static/version1/x.js', 'media/catalog/a.jpg', 'wp-login.php', '.env', 'favicon.ico', 'admin', 'x/y.css.map'] as $path) {
            self::assertFalse($missing->isPage($path), $path);
        }
    }
}

<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Test\Unit\Model;

use Magelan\AiVisibility\Model\Audit\PageAnalyzer;
use Magelan\AiVisibility\Model\Llms\Builder;
use PHPUnit\Framework\TestCase;

class PageAnalyzerTest extends TestCase
{
    public function testAGoodProductPage(): void
    {
        $text = str_repeat('Udobne bombažne hlače za vsak dan. ', 40);
        $html = '<!doctype html><html><head><title>Hlače Cora | Shop</title>'
            . '<meta name="description" content="Udobne hlače iz organskega bombaža, v petih barvah, z dostavo v dveh dneh.">'
            . '<link rel="canonical" href="https://shop.example/cora.html">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Cora",'
            . '"offers":{"@type":"Offer","price":"49.90","priceCurrency":"EUR","availability":"https://schema.org/InStock"},'
            . '"aggregateRating":{"@type":"AggregateRating","ratingValue":4.6,"reviewCount":12}}</script>'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"BreadcrumbList"}]}</script>'
            . '</head><body><h1>Hlače Cora</h1><p>' . $text . '</p><img src="a.jpg" alt="Hlače Cora"><script>var x = 1;</script></body></html>';

        $result = (new PageAnalyzer())->analyze($html, 200, [], 'https://shop.example/cora.html?utm=x');

        self::assertSame(['Product', 'BreadcrumbList'], $result['facts']['types']);
        self::assertSame('49.90 EUR', $result['facts']['price']);
        self::assertSame('InStock', $result['facts']['availability']);
        self::assertEquals(4.6, $result['facts']['rating']);
        self::assertSame(['Hlače Cora'], $result['facts']['h1']);
        self::assertGreaterThan(150, $result['facts']['words']);
        self::assertSame(100, $result['score']);
    }

    public function testAnEmptyScriptPageFails(): void
    {
        $html = '<html><head><title>App</title><meta name="robots" content="NOINDEX"></head><body><div id="root"></div>'
            . '<script>render()</script><img src="a.jpg"></body></html>';

        $result = (new PageAnalyzer())->analyze($html, 200, ['x-robots-tag' => ''], 'https://shop.example/');
        $failed = array_column(array_filter($result['checks'], static fn ($c) => $c['status'] === PageAnalyzer::FAIL), 'label');

        self::assertSame(0, $result['facts']['words']);
        self::assertContains('The page may be indexed', $failed);
        self::assertContains('Structured data (JSON-LD)', $failed);
        self::assertContains('Text readable without JavaScript', $failed);
        self::assertLessThan(50, $result['score']);
    }

    public function testLlmsLinesAreOneLineAndSafe(): void
    {
        $builder = (new \ReflectionClass(Builder::class))->newInstanceWithoutConstructor();

        self::assertSame(
            '- [Majica (rdeča)](https://shop.example/a%20b.html): Mehka. Zelo.',
            $builder->link("Majica [rdeča]\n", 'https://shop.example/a b.html', "  Mehka.\n\n Zelo. ")
        );
        self::assertSame(
            'Prvi odstavek. Drugi odstavek s…',
            $builder->excerpt('<p>Prvi odstavek.</p><style>.x{}</style><p>Drugi {{widget type="x"}} odstavek s precej besedami</p>', 32)
        );
    }
}

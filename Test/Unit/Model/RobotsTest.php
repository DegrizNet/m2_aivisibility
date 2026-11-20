<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Test\Unit\Model;

use Magelan\AiVisibility\Model\Audit\RobotsEvaluator;
use Magelan\AiVisibility\Model\BotCatalog;
use Magelan\AiVisibility\Model\Config;
use Magelan\AiVisibility\Model\RobotsRules;
use PHPUnit\Framework\TestCase;

class RobotsTest extends TestCase
{
    private const SHOP = "User-agent: *\nDisallow: /index.php/\nDisallow: /*?SID=\nAllow: /media/\n\nUser-agent: Yandex\nDisallow: /\n\nSitemap: https://shop.example/sitemap.xml\n";

    private function rules(array $policies, array $alwaysBlock = [], array $privatePaths = ['/checkout/']): RobotsRules
    {
        $config = $this->createMock(Config::class);
        $config->method('getPolicy')->willReturnCallback(static fn (string $kind): string => $policies[$kind] ?? Config::ALLOW);
        $config->method('getAlwaysBlocked')->willReturn($alwaysBlock);
        $config->method('getAlwaysAllowed')->willReturn([]);
        $config->method('getPrivatePaths')->willReturn($privatePaths);

        return new RobotsRules(new BotCatalog(), $config);
    }

    public function testStarRulesAreTakenOnlyFromTheStarGroup(): void
    {
        self::assertSame(
            ['Disallow: /index.php/', 'Disallow: /*?SID=', 'Allow: /media/'],
            $this->rules([])->starRules(self::SHOP)
        );
    }

    public function testBlockedTrainingAllowedSearch(): void
    {
        $text = $this->rules([BotCatalog::TRAINING => Config::BLOCK])->build(self::SHOP, 1);
        $robots = self::SHOP . $text;
        $evaluator = new RobotsEvaluator();

        self::assertFalse($evaluator->isAllowed($robots, 'GPTBot', '/shirt.html'));
        self::assertFalse($evaluator->isAllowed($robots, 'Google-Extended', '/'));
        self::assertTrue($evaluator->isAllowed($robots, 'OAI-SearchBot', '/shirt.html'));
        self::assertFalse($evaluator->isAllowed($robots, 'OAI-SearchBot', '/checkout/cart/'));
        // The shop's own "*" rules still bind the allowed AI crawlers.
        self::assertFalse($evaluator->isAllowed($robots, 'ChatGPT-User', '/index.php/admin'));
        self::assertTrue($evaluator->isAllowed($robots, 'ChatGPT-User', '/media/catalog/x.jpg'));
        // Bots we do not manage keep their own rules.
        self::assertFalse($evaluator->isAllowed($robots, 'Yandex', '/'));
        self::assertTrue($evaluator->isAllowed($robots, 'Googlebot', '/checkout/cart/'));
    }

    public function testAlwaysBlockWinsOverAllow(): void
    {
        $robots = self::SHOP . $this->rules([], ['PerplexityBot'])->build(self::SHOP, 1);

        self::assertFalse((new RobotsEvaluator())->isAllowed($robots, 'PerplexityBot', '/'));
        self::assertTrue((new RobotsEvaluator())->isAllowed($robots, 'ClaudeBot', '/'));
    }

    public function testNothingIsAddedWhenEverythingIsAllowedAndNoPathIsPrivate(): void
    {
        self::assertSame('', $this->rules([], [], [])->build(self::SHOP, 1));
    }

    public function testLongestRuleWinsAndAllowWinsATie(): void
    {
        $robots = "User-agent: *\nDisallow: /shop\nAllow: /shop/public\nDisallow: /*.pdf$\nAllow: /x\nDisallow: /x\n";
        $evaluator = new RobotsEvaluator();

        self::assertFalse($evaluator->isAllowed($robots, 'any', '/shop/private'));
        self::assertTrue($evaluator->isAllowed($robots, 'any', '/shop/public/page'));
        self::assertFalse($evaluator->isAllowed($robots, 'any', '/files/a.pdf'));
        self::assertTrue($evaluator->isAllowed($robots, 'any', '/files/a.pdf?x=1'));
        self::assertTrue($evaluator->isAllowed($robots, 'any', '/x'));
    }
}

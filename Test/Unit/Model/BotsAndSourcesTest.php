<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Test\Unit\Model;

use Magelan\AiVisibility\Model\AiSources;
use Magelan\AiVisibility\Model\BotCatalog;
use PHPUnit\Framework\TestCase;

class BotsAndSourcesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function agents(): array
    {
        return [
            'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'GPTBot'],
            'ChatGPT-User' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 'ChatGPT-User'],
            'ClaudeBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'ClaudeBot'],
            'Claude-User' => ['Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 'Claude-User'],
            'Perplexity-User' => ['Mozilla/5.0 (compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)', 'Perplexity-User'],
            'Meta' => ['meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)', 'Meta-ExternalAgent'],
            'Cohere training' => ['cohere-training-data-crawler/1.0', 'cohere-training-data-crawler'],
            'Googlebot is not an AI crawler' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', null],
            'Bingbot neither' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', null],
            'A browser' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36', null],
            'Empty' => ['', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('agents')]
    public function testMatch(string $userAgent, ?string $expected): void
    {
        self::assertSame($expected, (new BotCatalog())->match($userAgent));
    }

    public function testRobotsOnlyTokensAreNeverMatched(): void
    {
        $catalog = new BotCatalog();

        self::assertFalse($catalog->all()['Google-Extended']['logged']);
        self::assertNull($catalog->match('Google-Extended'));
        self::assertContains('Google-Extended', $catalog->tokensOfKind(BotCatalog::TRAINING));
    }

    public function testSourceDetection(): void
    {
        $sources = new AiSources();

        self::assertSame('chatgpt', $sources->detect('chatgpt.com', ''));
        self::assertSame('chatgpt', $sources->detect('', 'chatgpt.com'));
        self::assertSame('perplexity', $sources->detect('www.perplexity.ai', ''));
        self::assertSame('gemini', $sources->detect('gemini.google.com', ''));
        self::assertNull($sources->detect('www.google.com', ''));
        self::assertNull($sources->detect('notperplexity.ai', ''));
        self::assertTrue($sources->isKnown('claude'));
        self::assertFalse($sources->isKnown('facebook'));
    }
}

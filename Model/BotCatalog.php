<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model;

/**
 * The AI crawlers we know, what each one is for, and how to recognise it.
 *
 * Three kinds, because a shop answers them differently:
 * - training: reads pages to train a model. Blocking it costs nothing today.
 * - search: builds the index an AI assistant searches when it answers. Blocking it means the shop
 *   is not among the answers.
 * - user: fetches one page because a person asked the assistant about it, right now. Blocking it
 *   means the assistant tells that person it could not open the shop.
 *
 * Some names are only robots.txt tokens with no crawler of their own (Google-Extended,
 * Applebot-Extended): they can be allowed or refused, but never seen in the log.
 */
class BotCatalog
{
    public const TRAINING = 'training';
    public const SEARCH = 'search';
    public const USER = 'user';

    /**
     * token => [company, kind, seen in the user agent]
     */
    private const BOTS = [
        'GPTBot' => ['OpenAI', self::TRAINING, true],
        'OAI-SearchBot' => ['OpenAI', self::SEARCH, true],
        'ChatGPT-User' => ['OpenAI', self::USER, true],
        'ClaudeBot' => ['Anthropic', self::TRAINING, true],
        'Claude-SearchBot' => ['Anthropic', self::SEARCH, true],
        'Claude-User' => ['Anthropic', self::USER, true],
        'PerplexityBot' => ['Perplexity', self::SEARCH, true],
        'Perplexity-User' => ['Perplexity', self::USER, true],
        'Google-Extended' => ['Google', self::TRAINING, false],
        'Google-CloudVertexBot' => ['Google', self::USER, true],
        'Applebot-Extended' => ['Apple', self::TRAINING, false],
        'Meta-ExternalAgent' => ['Meta', self::TRAINING, true],
        'Meta-ExternalFetcher' => ['Meta', self::USER, true],
        'Amazonbot' => ['Amazon', self::SEARCH, true],
        'DuckAssistBot' => ['DuckDuckGo', self::USER, true],
        'MistralAI-User' => ['Mistral', self::USER, true],
        'Bytespider' => ['ByteDance', self::TRAINING, true],
        'CCBot' => ['Common Crawl', self::TRAINING, true],
        'cohere-ai' => ['Cohere', self::USER, true],
        'cohere-training-data-crawler' => ['Cohere', self::TRAINING, true],
        'YouBot' => ['You.com', self::SEARCH, true],
        'AI2Bot' => ['Allen Institute', self::TRAINING, true],
        'Diffbot' => ['Diffbot', self::TRAINING, true],
        'PanguBot' => ['Huawei', self::TRAINING, true],
        'Timpibot' => ['Timpi', self::TRAINING, true],
        'ImagesiftBot' => ['Hive', self::TRAINING, true],
    ];

    private ?string $pattern = null;

    /**
     * @return array<string, array{company: string, kind: string, logged: bool}>
     */
    public function all(): array
    {
        $bots = [];

        foreach (self::BOTS as $token => [$company, $kind, $logged]) {
            $bots[$token] = ['company' => $company, 'kind' => $kind, 'logged' => $logged];
        }

        return $bots;
    }

    /**
     * @return string[]
     */
    public function tokensOfKind(string $kind): array
    {
        return array_keys(array_filter(self::BOTS, static fn (array $bot): bool => $bot[1] === $kind));
    }

    public function kindOf(string $token): string
    {
        return self::BOTS[$token][1] ?? '';
    }

    /**
     * The bot a user agent belongs to, or null for everything else - people, Googlebot, Bingbot.
     */
    public function match(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        if ($this->pattern === null) {
            $tokens = array_keys(array_filter(self::BOTS, static fn (array $bot): bool => $bot[2]));
            // Longest first, so "cohere-training-data-crawler" is not taken for "cohere-ai"'s sibling.
            usort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $this->pattern = '~(?<![a-z0-9-])(' . implode('|', array_map(
                static fn (string $token): string => preg_quote($token, '~'),
                $tokens
            )) . ')(?![a-z0-9-])~i';
        }

        if (preg_match($this->pattern, $userAgent, $match) !== 1) {
            return null;
        }

        foreach (array_keys(self::BOTS) as $token) {
            if (strcasecmp($token, $match[1]) === 0) {
                return $token;
            }
        }

        return null;
    }
}

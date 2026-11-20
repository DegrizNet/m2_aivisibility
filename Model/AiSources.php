<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model;

/**
 * The AI assistants a visitor can come from, recognised by the referring host or by utm_source.
 *
 * The same list drives the browser script (as JSON) and the server, so a source the script reports
 * is always one the server accepts.
 */
class AiSources
{
    /**
     * source => [label, hosts (a referrer ending in one of them), utm_source values]
     */
    private const SOURCES = [
        'chatgpt' => ['ChatGPT', ['chatgpt.com', 'chat.openai.com'], ['chatgpt.com', 'chatgpt', 'openai']],
        'perplexity' => ['Perplexity', ['perplexity.ai'], ['perplexity', 'perplexity.ai']],
        'claude' => ['Claude', ['claude.ai'], ['claude', 'claude.ai']],
        'gemini' => ['Gemini', ['gemini.google.com', 'bard.google.com'], ['gemini', 'gemini.google.com']],
        'copilot' => ['Copilot', ['copilot.microsoft.com', 'copilot.cloud.microsoft'], ['copilot', 'copilot.microsoft.com']],
        'deepseek' => ['DeepSeek', ['chat.deepseek.com'], ['deepseek']],
        'grok' => ['Grok', ['grok.com'], ['grok', 'grok.com']],
        'mistral' => ['Le Chat (Mistral)', ['chat.mistral.ai'], ['mistral', 'lechat']],
        'meta' => ['Meta AI', ['meta.ai'], ['meta.ai']],
        'you' => ['You.com', ['you.com'], ['you.com']],
        'poe' => ['Poe', ['poe.com'], ['poe']],
    ];

    /**
     * @return array<string, string> source => label
     */
    public function labels(): array
    {
        return array_map(static fn (array $source): string => $source[0], self::SOURCES);
    }

    public function isKnown(string $source): bool
    {
        return isset(self::SOURCES[$source]);
    }

    /**
     * The source of a visit, from where it came and its utm_source, or null.
     */
    public function detect(string $referrerHost, string $utmSource): ?string
    {
        $referrerHost = strtolower($referrerHost);
        $utmSource = strtolower(trim($utmSource));

        foreach (self::SOURCES as $source => [, $hosts, $utms]) {
            foreach ($hosts as $host) {
                if ($referrerHost === $host || str_ends_with($referrerHost, '.' . $host)) {
                    return $source;
                }
            }

            if ($utmSource !== '' && in_array($utmSource, $utms, true)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * The table the browser script needs: [[source, [hosts], [utm values]], ...].
     *
     * @return array<int, array{0: string, 1: string[], 2: string[]}>
     */
    public function forScript(): array
    {
        $out = [];

        foreach (self::SOURCES as $source => [, $hosts, $utms]) {
            $out[] = [$source, $hosts, $utms];
        }

        return $out;
    }
}

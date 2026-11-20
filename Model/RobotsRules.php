<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model;

/**
 * The AI crawler groups added to robots.txt.
 *
 * A crawler that finds a group with its own name ignores the "User-agent: *" group completely. So
 * a group for allowed AI bots repeats the shop's own "*" rules, or adding it would quietly open
 * everything the shop had closed - the account pages, the search results.
 */
class RobotsRules
{
    public function __construct(
        private readonly BotCatalog $catalog,
        private readonly Config $config
    ) {
    }

    public function build(string $existing, int $storeId): string
    {
        $blocked = [];
        $allowed = [];
        $alwaysBlocked = $this->config->getAlwaysBlocked($storeId);
        $alwaysAllowed = $this->config->getAlwaysAllowed($storeId);

        foreach ($this->catalog->all() as $token => $bot) {
            $block = in_array($token, $alwaysBlocked, true)
                || ($this->config->getPolicy($bot['kind'], $storeId) === Config::BLOCK && !in_array($token, $alwaysAllowed, true));

            if ($block) {
                $blocked[] = $token;
            } else {
                $allowed[] = $token;
            }
        }

        return $this->render($blocked, $allowed, $this->starRules($existing), $this->config->getPrivatePaths($storeId));
    }

    /**
     * @param string[] $blocked
     * @param string[] $allowed
     * @param string[] $starRules
     * @param string[] $privatePaths
     */
    public function render(array $blocked, array $allowed, array $starRules, array $privatePaths): string
    {
        $lines = ['', '# AI crawlers (Magelan AI Visibility)'];

        if ($blocked !== []) {
            foreach ($blocked as $token) {
                $lines[] = 'User-agent: ' . $token;
            }

            $lines[] = 'Disallow: /';
            $lines[] = '';
        }

        // Allowed bots need a group of their own only when they get rules the "*" group does not
        // have; otherwise they already follow "*".
        if ($allowed !== [] && $privatePaths !== []) {
            foreach ($allowed as $token) {
                $lines[] = 'User-agent: ' . $token;
            }

            $rules = $starRules;

            foreach ($privatePaths as $path) {
                $rules[] = 'Disallow: ' . $path;
            }

            foreach (array_values(array_unique($rules)) as $rule) {
                $lines[] = $rule;
            }

            $lines[] = 'Allow: /';
            $lines[] = '';
        }

        return count($lines) > 2 ? implode("\n", $lines) : '';
    }

    /**
     * The Allow and Disallow lines of the shop's own "User-agent: *" group(s).
     *
     * @return string[]
     */
    public function starRules(string $robots): array
    {
        $rules = [];
        $inStar = false;
        $lastWasAgent = false;

        foreach (preg_split('/\r?\n/', $robots) ?: [] as $line) {
            $line = trim((string)preg_replace('/#.*$/', '', $line));

            if ($line === '') {
                continue;
            }

            if (preg_match('/^user-agent\s*:\s*(.+)$/i', $line, $match)) {
                // Consecutive User-agent lines form one group.
                $isStar = trim($match[1]) === '*';
                $inStar = $lastWasAgent ? ($inStar || $isStar) : $isStar;
                $lastWasAgent = true;
                continue;
            }

            $lastWasAgent = false;

            if ($inStar && preg_match('/^(allow|disallow)\s*:\s*(.*)$/i', $line, $match)) {
                $rules[] = ucfirst(strtolower($match[1])) . ': ' . trim($match[2]);
            }
        }

        return array_values(array_unique(array_filter($rules, static fn (string $rule): bool => $rule !== 'Disallow: ')));
    }
}

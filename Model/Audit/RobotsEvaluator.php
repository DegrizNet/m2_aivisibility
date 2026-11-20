<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Model\Audit;

/**
 * Answers "may this bot read this path?" from a robots.txt, the way Google documents it (RFC 9309):
 * the bot's own group if there is one, else "*"; the longest matching rule wins; Allow wins a tie;
 * "*" and "$" in paths.
 */
class RobotsEvaluator
{
    public function isAllowed(string $robots, string $token, string $path): bool
    {
        $groups = $this->groups($robots);
        $rules = $groups[strtolower($token)] ?? $groups['*'] ?? [];
        $best = null;

        foreach ($rules as [$allow, $pattern]) {
            if ($pattern === '' || !$this->matches($pattern, $path)) {
                continue;
            }

            $length = strlen($pattern);

            if ($best === null || $length > $best[1] || ($length === $best[1] && $allow)) {
                $best = [$allow, $length];
            }
        }

        return $best === null || $best[0];
    }

    /**
     * @return array<string, array<int, array{0: bool, 1: string}>> agent (lower case) => rules
     */
    private function groups(string $robots): array
    {
        $groups = [];
        $agents = [];
        $collecting = false;

        foreach (preg_split('/\r?\n/', $robots) ?: [] as $line) {
            $line = trim((string)preg_replace('/#.*$/', '', $line));

            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (!$collecting) {
                    $agents = [];
                }

                $agents[] = strtolower($value);
                $collecting = true;
                continue;
            }

            if ($field === 'allow' || $field === 'disallow') {
                $collecting = false;

                foreach ($agents as $agent) {
                    $groups[$agent][] = [$field === 'allow', $value];
                }
            }
        }

        return $groups;
    }

    private function matches(string $pattern, string $path): bool
    {
        $regex = '~^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '~')) . '~';

        return preg_match($regex, $path) === 1;
    }
}

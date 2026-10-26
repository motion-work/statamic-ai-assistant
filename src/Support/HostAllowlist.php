<?php

namespace MotionWork\StatamicAiAssistant\Support;

/**
 * Host-matching for the agent's outbound-URL provenance rules. A candidate URL
 * is "allowed" when its host matches one the user actually referenced — exactly,
 * as a subdomain of it, or as its parent domain (covers www/CDN/subdomain
 * variants of the same site the user pointed to).
 *
 * Shared by every tool that reaches out to a URL — page fetching
 * ({@see \MotionWork\StatamicAiAssistant\Tools\UrlFetchTool}) and image saving
 * ({@see \MotionWork\StatamicAiAssistant\Tools\SaveImageTool}) — so both apply the
 * exact same rule instead of each rolling their own.
 */
final class HostAllowlist
{
    /**
     * @param  array<int, string>  $allowedHosts  Lowercased hosts the user referenced.
     */
    public static function matches(string $url, array $allowedHosts): bool
    {
        if ($url === '' || $allowedHosts === []) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = self::normalize($host);

        foreach ($allowedHosts as $allowed) {
            $allowed = self::normalize((string) $allowed);
            if ($allowed === '') {
                continue;
            }

            if ($host === $allowed
                || str_ends_with($host, '.'.$allowed)
                || str_ends_with($allowed, '.'.$host)) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));

        return preg_replace('~^www\.~', '', $host) ?? $host;
    }
}

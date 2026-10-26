<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;

/**
 * Per-run side-channels a ChatTool may need. Bundling them here means tools with
 * very different needs (a URL fetcher, an image saver, an entry reader) all share
 * one uniform `handle(argsJson, ToolContext)` signature — the runner never has to
 * special-case a tool's collaborators.
 *
 * Callables are optional; a tool simply ignores what it does not use.
 */
class ToolContext
{
    /**
     * @param  (callable(string): void)|null  $warningSink  Receives user-facing warnings
     * @param  (callable(string): void)|null  $onStreamToken  CP drawer streaming notifier
     * @param  (callable(): void)|null  $heartbeat  Keeps long streams alive between tool calls
     * @param  PreferredAssetPaths|null  $imageSink  Queue the image tool appends saved assets to
     * @param  string|null  $imageContainerHint  Asset container the entry's image fields point at
     * @param  (callable(string): void)|null  $activitySink  Receives short human-readable progress lines ("Reading …")
     */
    public function __construct(
        private $warningSink = null,
        private $onStreamToken = null,
        private $heartbeat = null,
        public readonly ?PreferredAssetPaths $imageSink = null,
        public readonly ?string $imageContainerHint = null,
        private $activitySink = null,
    ) {}

    public function addWarning(string $message): void
    {
        if ($this->warningSink !== null) {
            ($this->warningSink)($message);
        }
    }

    /**
     * Report a short, human-readable progress step (e.g. "Reading example.com/events")
     * for the CP activity feed. No-op when no sink is wired.
     */
    public function reportActivity(string $line): void
    {
        if ($this->activitySink !== null && trim($line) !== '') {
            ($this->activitySink)($line);
        }
    }

    public function onStreamToken(): ?callable
    {
        return $this->onStreamToken;
    }

    public function heartbeat(): void
    {
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }
    }

    // ── URL provenance ───────────────────────────────────────────────────
    // Which hosts/URLs actually came from content the agent legitimately
    // retrieved this run. save_remote_image consults this so it can only pull
    // images the user pointed at or that appeared in a fetched page — never a
    // URL the model invented from memory (mirrors the fetch tool's allowlist).

    /** @var array<string, true> hosts of pages successfully fetched this run */
    private array $fetchedHosts = [];

    /** @var array<string, true> set of absolute URLs seen inside fetched content */
    private array $fetchedUrls = [];

    /**
     * Record a completed fetch: the requested page's host, plus every http(s)
     * URL found in the returned body (so an image on a page's CDN counts as
     * legitimately seen even when its host differs from the page's).
     */
    public function rememberFetchedContent(string $requestedUrl, string $body): void
    {
        $host = parse_url($requestedUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $this->fetchedHosts[strtolower($host)] = true;
        }

        if (preg_match_all('~https?://[^\s"\'<>)\]}]+~i', $body, $m)) {
            foreach ($m[0] as $url) {
                $this->fetchedUrls[rtrim($url, '.,;:!?)]}')] = true;
            }
        }
    }

    /** Hosts of pages fetched this run (lowercased). @return array<int, string> */
    public function fetchedHosts(): array
    {
        return array_keys($this->fetchedHosts);
    }

    /** Whether an exact URL appeared in content fetched this run. */
    public function sawFetchedUrl(string $url): bool
    {
        return isset($this->fetchedUrls[rtrim(trim($url), '.,;:!?)]}')]);
    }
}

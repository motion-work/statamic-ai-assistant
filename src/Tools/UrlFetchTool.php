<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use MotionWork\StatamicAiAssistant\Services\PromptUrlFetcher;
use MotionWork\StatamicAiAssistant\Support\HostAllowlist;
use Illuminate\Support\Str;

/**
 * ChatTool adapter over PromptUrlFetcher's fetch_page_content capability. The
 * fetcher keeps its own responsibilities (inline prompt augmentation, caching);
 * this thin adapter just exposes it through the shared ChatTool contract.
 *
 * By default the agent may only fetch URLs the user actually provided (host
 * allowlist derived from the prompt), plus same-site links found within those
 * pages. This stops the model from spidering the open web on its own initiative
 * and, in particular, from burning the fetch budget on invented URLs that don't
 * exist. Pass an empty $allowedHosts with $restrict = true to forbid all fetches
 * (the user referenced no source, so there is nothing legitimate to open).
 */
class UrlFetchTool implements ChatTool
{
    /**
     * @param  array<int, string>  $allowedHosts  Hosts the user referenced (lowercased).
     */
    public function __construct(
        private PromptUrlFetcher $fetcher,
        private array $allowedHosts = [],
        private bool $restrict = true,
    ) {}

    public function name(): string
    {
        return 'fetch_page_content';
    }

    public function definition(): array
    {
        return $this->fetcher->chatToolDefinition();
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        $args = json_decode($argumentsJson, true);
        $url = (is_array($args) && isset($args['url']) && is_string($args['url'])) ? trim($args['url']) : '';

        if ($this->restrict && ! $this->isUrlAllowed($url)) {
            $context->addWarning((string) __('Skipped fetching :url — the agent only opens links you provide.', [
                'url' => Str::limit($url !== '' ? $url : '(none)', 80),
            ]));

            return [
                'ok' => false,
                'error' => 'url_not_allowed',
                'url' => $url,
                'message' => $this->allowedHosts === []
                    ? 'Fetching is disabled: the user did not provide any URL to open. Do NOT invent, guess, or try URLs. '
                        .'Write the entry from the CMS context and the information you already have.'
                    : 'Fetching is restricted to the URL(s) the user provided and same-site links within them. '
                        .'Do NOT invent, guess, or try alternative/variant URLs. Continue with the information you already have.',
            ];
        }

        if ($url !== '') {
            $short = preg_replace('~^https?://~i', '', $url);
            $context->reportActivity((string) __('Reading :url', ['url' => Str::limit((string) $short, 60)]));
        }

        $warnings = [];
        $json = $this->fetcher->executeChatTool($argumentsJson, $context->onStreamToken(), $warnings);
        foreach ($warnings as $w) {
            $context->addWarning((string) $w);
        }

        $decoded = json_decode($json, true);

        // Record provenance so save_remote_image may later pull images that
        // actually appeared on this page (or its CDN) — but nothing invented.
        if (is_array($decoded) && ($decoded['ok'] ?? false) !== false) {
            $context->rememberFetchedContent($url, $json);
        }

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'tool_error'];
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_fetches', 100));
    }

    /**
     * Collect the fetch allowlist from the user turns of a chat message array
     * (covers a URL given earlier in a multi-turn conversation, not just now).
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, string>
     */
    public static function hostsFromMessages(PromptUrlFetcher $fetcher, array $messages): array
    {
        $hosts = [];

        foreach ($messages as $m) {
            if (($m['role'] ?? '') !== 'user') {
                continue;
            }

            $content = $m['content'] ?? '';
            if (! is_string($content) || $content === '') {
                continue;
            }

            foreach ($fetcher->allowedHostsIn($content) as $host) {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * A URL is fetchable when its host matches a user-provided host — exactly, as
     * a subdomain of it, or as its parent domain (covers www/CDN/subdomain
     * variants of the same site the user pointed to).
     */
    private function isUrlAllowed(string $url): bool
    {
        return HostAllowlist::matches($url, $this->allowedHosts);
    }
}

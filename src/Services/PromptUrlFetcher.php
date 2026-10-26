<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Detects http(s) URLs in a user prompt and pulls readable text via Jina Reader
 * (https://r.jina.ai/{url}) so the LLM can use page content without native browsing.
 */
class PromptUrlFetcher
{
    /**
     * CSS selectors stripped from a page by Jina Reader before extraction
     * (sent via the X-Remove-Selector header). Keeps site chrome — nav, header,
     * footer, sidebars and, crucially, consent-manager dialogs (Cookiebot,
     * OneTrust, Usercentrics) — out of the fetched text. Consent banners inject
     * tens of thousands of characters of cookie-declaration boilerplate at the
     * top of the body; left in, they exhaust max_chars_per_url and the real
     * content gets truncated away before the LLM ever sees it.
     *
     * This is the single source of truth: config/statamic-ai-assistant.php
     * references it as the default for `prompt_url_fetch.remove_selector`, and
     * all fetch paths read that config value via readerRemoveHeaders().
     */
    public const DEFAULT_REMOVE_SELECTOR =
        'nav, header, footer, aside, '
        .'[role="navigation"], [role="banner"], [role="contentinfo"], [role="complementary"], '
        .'.nav, .navbar, .navigation, .menu, .header, .site-header, .footer, .site-footer, '
        .'.sidebar, .breadcrumbs, .breadcrumb, '
        .'.cookie-banner, .cookie-consent, .cookies, '
        // Cookiebot
        .'#CybotCookiebotDialog, #CybotCookiebotDialogBodyUnderlay, #CookieDeclaration, #CookiebotWidget, '
        // OneTrust
        .'#onetrust-banner-sdk, #onetrust-consent-sdk, #ot-sdk-cookie-policy, '
        // Usercentrics
        .'#usercentrics-root, #usercentrics-cmp-ui, '
        .'.skip-link, .share, .social, .related, .related-posts, .you-may-also-like';

    /** @var array<string, array{ok: bool, body: string, error: ?string}> */
    private static array $fetchCache = [];

    private ?HtmlReadableExtractor $htmlExtractor = null;

    private function htmlExtractor(): HtmlReadableExtractor
    {
        return $this->htmlExtractor ??= new HtmlReadableExtractor;
    }

    /**
     * Reader chrome-stripping header, shared by every fetch path so the inline
     * prompt augmentation and the entry-generator tool remove the same site
     * furniture. Returns [] when stripping is disabled.
     *
     * @return array<string, string>
     */
    private function readerRemoveHeaders(): array
    {
        $selector = trim((string) config(
            'statamic-ai-assistant.prompt_url_fetch.remove_selector',
            self::DEFAULT_REMOVE_SELECTOR,
        ));

        return $selector !== '' ? ['X-Remove-Selector' => $selector] : [];
    }

    /**
     * @return array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths}
     */
    public function buildAugmentation(string $prompt): array
    {
        $warnings = [];
        $appendix = '';

        if (! (bool) config('statamic-ai-assistant.prompt_url_fetch.enabled', true)) {
            return ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths];
        }

        $urls = $this->extractPublicHttpUrls($prompt);

        if ($urls === []) {
            return ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths];
        }

        $maxUrls = max(1, (int) config('statamic-ai-assistant.prompt_url_fetch.max_urls', 5));
        $maxPer = max(1000, (int) config('statamic-ai-assistant.prompt_url_fetch.max_chars_per_url', 12000));
        $maxTotal = max($maxPer, (int) config('statamic-ai-assistant.prompt_url_fetch.max_total_chars', 40000));
        $timeout = max(5, (int) config('statamic-ai-assistant.prompt_url_fetch.timeout', 25));
        $base = rtrim((string) config('statamic-ai-assistant.prompt_url_fetch.reader_base', 'https://r.jina.ai'), '/');
        $apiKey = config('statamic-ai-assistant.prompt_url_fetch.api_key');

        $urls = array_slice($urls, 0, $maxUrls);

        $blocks = [];
        $totalUsed = 0;

        foreach ($urls as $url) {
            if ($totalUsed >= $maxTotal) {
                $warnings[] = __('URL context was truncated: reached the maximum amount of fetched text.');

                break;
            }

            $result = $this->fetchViaJina($url, $base, $timeout, $apiKey ? (string) $apiKey : null, $this->readerRemoveHeaders());

            if (! $result['ok']) {
                $warnings[] = __('Could not fetch :url: :reason', [
                    'url' => $url,
                    'reason' => $result['error'] ?? __('unavailable or blocked'),
                ]);

                continue;
            }

            $body = $result['body'];

            $chunk = Str::limit($body, $maxPer);
            $remaining = $maxTotal - $totalUsed;

            if (strlen($chunk) > $remaining) {
                $chunk = Str::limit($chunk, $remaining);
            }

            $totalUsed += strlen($chunk);

            // Surface the source page's H1 explicitly so the model can pin the
            // entry title to it verbatim instead of paraphrasing the body.
            $headlineLine = ! empty($result['headline'])
                ? 'Page title (H1): '.$result['headline']."\n\n"
                : '';

            $blocks[] = '### '.$url."\n\n".$headlineLine.$chunk;
        }

        if ($blocks !== []) {
            $appendix = "\n\n--- ".__('Content fetched from URLs in your prompt (reader service)')." ---\n\n"
                .implode("\n\n---\n\n", $blocks);
        }

        return [
            'appendix' => $appendix,
            'warnings' => $warnings,
            'preferred' => new PreferredAssetPaths,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractPublicHttpUrls(string $text): array
    {
        if (! preg_match_all('~\bhttps?://[^\s<>\]\}\)\"\'`]+~iu', $text, $m)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($m[0] as $raw) {
            $url = $this->normalizeUrlCandidate($raw);

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            if (! $this->isUrlAllowed($url)) {
                continue;
            }

            $seen[$url] = true;
            $out[] = $url;
        }

        return $out;
    }

    private function normalizeUrlCandidate(string $raw): ?string
    {
        $url = rtrim($raw, '.,;:!?)]}\'"');

        if (strlen($url) > 2048) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private function isUrlAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $hostLower = strtolower($host);

        if ($hostLower === 'localhost' || str_ends_with($hostLower, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        // Figma URLs are handled by FigmaContentFetcher with OAuth — don't
        // try to scrape them through the public reader.
        if ($hostLower === 'figma.com' || str_ends_with($hostLower, '.figma.com')) {
            return false;
        }

        return true;
    }

    /**
     * OpenAI-style tool definition exposed to the LLM for on-demand URL fetches.
     * Shared by the planner and the entry generator so both go through the same
     * fetch path (and the same Jina cache).
     *
     * @return array{type: string, function: array<string, mixed>}
     */
    public function chatToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'fetch_page_content',
                'description' => 'Fetches readable full-page text from a public http(s) URL via the server reader. '
                    .'Use to inspect a URL the user referenced — for example, to count items on a listing page, '
                    .'discover linked detail pages, or read article bodies. The result includes an "h1" field with the '
                    .'page\'s main heading — use it verbatim as the entry title when copying that page. '
                    .'Always pass a concrete reason so the tool result is traceable.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Full URL to fetch (https preferred).',
                        ],
                        'reason' => [
                            'type' => 'string',
                            'description' => 'Why this URL is needed, tied to the user task (e.g. which item, which field, what is missing from current context).',
                        ],
                        'scope' => [
                            'type' => 'string',
                            'enum' => ['main_content', 'full_page'],
                            'description' => 'Use "main_content" (default) to read an article/detail page body. '
                                .'Use "full_page" for a listing/overview page when you need the teaser links to discover detail pages to fetch next.',
                        ],
                    ],
                    'required' => ['url', 'reason'],
                ],
            ],
        ];
    }

    /**
     * Run a single tool call from the LLM: parse arguments, fetch the URL, and
     * return the JSON-encoded result the model should see in its tool message.
     *
     * @param  callable(string): void|null  $onStreamToken  Optional CP drawer notifier
     * @param  array<int, string>  $warningsOut  Per-request warning sink
     */
    public function executeChatTool(string $argumentsJson, ?callable $onStreamToken, array &$warningsOut): string
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('[entry-gen-tool] invalid tool arguments JSON', [
                'error' => $e->getMessage(),
                'arguments_excerpt' => Str::limit($argumentsJson, 300),
            ]);

            return json_encode([
                'ok' => false,
                'error' => 'invalid_arguments_json',
                'reason_echo' => '',
                'url' => '',
            ], JSON_UNESCAPED_UNICODE);
        }

        if (! is_array($args)) {
            return json_encode(['ok' => false, 'error' => 'invalid_arguments', 'reason_echo' => '', 'url' => ''], JSON_UNESCAPED_UNICODE);
        }

        $url = isset($args['url']) && is_string($args['url']) ? trim($args['url']) : '';
        $reason = isset($args['reason']) && is_string($args['reason']) ? trim($args['reason']) : '';
        $scope = (isset($args['scope']) && $args['scope'] === 'full_page')
            ? HtmlReadableExtractor::SCOPE_FULL
            : HtmlReadableExtractor::SCOPE_MAIN;

        if ($url === '' || $reason === '') {
            return json_encode([
                'ok' => false,
                'error' => 'url_and_reason_required',
                'reason_echo' => $reason,
                'url' => $url,
            ], JSON_UNESCAPED_UNICODE);
        }

        if ($onStreamToken) {
            $onStreamToken("\n\n[".__('Fetching: :url', ['url' => $url])." — {$reason}]\n\n");
        }

        $result = $this->fetchSingle($url, $scope);

        if (! $result['ok']) {
            $err = $result['error'] ?? __('unavailable or blocked');

            Log::warning('[entry-gen-tool] fetch failed', [
                'url' => $url,
                'reason' => $reason,
                'error' => $err,
            ]);

            $warningsOut[] = __('Could not fetch :url: :reason', [
                'url' => $url,
                'reason' => $err,
            ]);

            return json_encode([
                'ok' => false,
                'url' => $url,
                'reason_echo' => $reason,
                'error' => $err,
            ], JSON_UNESCAPED_UNICODE);
        }

        $maxChars = max(500, (int) config('statamic-ai-assistant.prompt_url_fetch.max_chars_per_url', 12000));
        $body = Str::limit($result['body'], $maxChars);
        $headline = ! empty($result['headline']) ? (string) $result['headline'] : null;

        Log::info('[entry-gen-tool] fetched', [
            'url' => $url,
            'reason' => $reason,
            'chars' => strlen($body),
            'h1' => $headline,
        ]);

        return json_encode(array_filter([
            'ok' => true,
            'url' => $url,
            'reason_echo' => $reason,
            // The source page's H1. Use this verbatim as the entry title when
            // copying this page, unless the user explicitly asked for another.
            'h1' => $headline,
            'content' => $body,
        ], fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Fetch a single URL via the configured reader.
     *
     * Used when a single page URL needs to be processed on its own and the Jina
     * response needs to surface verbatim on failure. Strips site chrome
     * (nav/header/footer/sidebar/consent dialogs) at fetch time via Jina's
     * X-Remove-Selector so it doesn't leak into the generated entry. Uses the
     * same selector as every other fetch path.
     *
     * @return array{ok: bool, body: string, error: ?string, headline?: ?string}
     */
    public function fetchSingle(string $url, string $scope = HtmlReadableExtractor::SCOPE_MAIN): array
    {
        $timeout = max(5, (int) config('statamic-ai-assistant.prompt_url_fetch.timeout', 25));
        $base = rtrim((string) config('statamic-ai-assistant.prompt_url_fetch.reader_base', 'https://r.jina.ai'), '/');
        $apiKey = config('statamic-ai-assistant.prompt_url_fetch.api_key');

        return $this->fetchViaJina(
            $url,
            $base,
            $timeout,
            $apiKey ? (string) $apiKey : null,
            $this->readerRemoveHeaders(),
            $scope,
        );
    }

    /**
     * Fetch a URL via Jina and return readable markdown.
     *
     * In the default 'html' reader_format we ask Jina for raw HTML (chrome still
     * stripped via X-Remove-Selector) and run our own DOM extraction +
     * html→markdown, because Jina's built-in markdown mode drops the real
     * article on listing-heavy pages. $extractScope decides whether we narrow to
     * the <main>/<article> landmark (copying content) or keep the full body
     * (discovering teasers/links). If HTML extraction yields nothing usable we
     * fall back to Jina's own markdown so a page never comes back empty.
     *
     * @param  array<string, string>  $extraHeaders
     * @return array{ok: bool, body: string, error: ?string, headline?: ?string}
     */
    private function fetchViaJina(string $targetUrl, string $readerBase, int $timeout, ?string $apiKey, array $extraHeaders = [], string $extractScope = HtmlReadableExtractor::SCOPE_MAIN): array
    {
        $format = strtolower((string) config('statamic-ai-assistant.prompt_url_fetch.reader_format', 'html'));

        if ($format === 'html') {
            $raw = $this->fetchRaw(
                $targetUrl,
                $readerBase,
                $timeout,
                $apiKey,
                array_merge($extraHeaders, ['X-Return-Format' => 'html']),
            );

            // Transport/HTTP failure: surface it verbatim, don't mask with a retry.
            if (! $raw['ok']) {
                return $raw;
            }

            $readable = $this->htmlExtractor()->extractReadable($raw['body'], $extractScope);
            if ($readable['markdown'] !== '') {
                return [
                    'ok' => true,
                    'body' => $readable['markdown'],
                    'error' => null,
                    'headline' => $readable['headline'],
                ];
            }

            Log::notice('HTML extraction empty, falling back to markdown reader', ['url' => $targetUrl]);
        }

        $raw = $this->fetchRaw($targetUrl, $readerBase, $timeout, $apiKey, $extraHeaders);

        // Markdown reader mode (or the empty-HTML fallback): recover the H1 from
        // the leading markdown heading so title-pinning still works.
        if ($raw['ok']) {
            $raw['headline'] = $this->headlineFromMarkdown($raw['body']);
        }

        return $raw;
    }

    /**
     * First level-1 markdown heading ("# Title") in reader output, used when we
     * don't have raw HTML to pull an <h1> from.
     */
    private function headlineFromMarkdown(string $markdown): ?string
    {
        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
            if (preg_match('/^\s*#\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $title = trim($m[1]);

                return $title !== '' ? $title : null;
            }
        }

        return null;
    }

    /**
     * Raw fetch from the reader with caching — no extraction, returns exactly
     * what Jina sent (markdown or HTML depending on the headers).
     *
     * @param  array<string, string>  $extraHeaders
     * @return array{ok: bool, body: string, error: ?string}
     */
    private function fetchRaw(string $targetUrl, string $readerBase, int $timeout, ?string $apiKey, array $extraHeaders = []): array
    {
        // Cache key includes header fingerprint so two callers asking for the
        // same URL with different selectors don't share a result.
        $cacheKey = $extraHeaders === [] ? $targetUrl : $targetUrl.'|'.md5(serialize($extraHeaders));
        if (isset(self::$fetchCache[$cacheKey])) {
            return self::$fetchCache[$cacheKey];
        }

        // Jina Reader expects: https://r.jina.ai/https://example.com/path
        $readerUrl = $readerBase.'/'.$targetUrl;

        try {
            $headers = array_merge([
                'User-Agent' => 'StatamicAiAssistant/1.0 (+https://statamic.com)',
                'Accept' => 'text/plain,text/markdown,*/*;q=0.8',
            ], $extraHeaders);

            if ($apiKey !== null && ($token = trim($apiKey)) !== '') {
                $headers['Authorization'] = 'Bearer '.$token;
            }

            $response = Http::timeout($timeout)->withHeaders($headers)->get($readerUrl);
        } catch (\Throwable $e) {
            Log::notice('Jina Reader fetch failed', ['url' => $targetUrl, 'message' => $e->getMessage()]);
            $out = ['ok' => false, 'body' => '', 'error' => $e->getMessage()];
            self::$fetchCache[$cacheKey] = $out;

            return $out;
        }

        if (! $response->successful()) {
            $hint = Str::limit(trim((string) ($response->json('message') ?? $response->body())), 200);
            $out = [
                'ok' => false,
                'body' => '',
                'error' => $hint !== '' ? $hint : (string) __('HTTP :code', ['code' => $response->status()]),
            ];
            self::$fetchCache[$cacheKey] = $out;

            return $out;
        }

        $body = trim((string) $response->body());

        if ($body === '') {
            $out = ['ok' => false, 'body' => '', 'error' => (string) __('Empty response')];
            self::$fetchCache[$cacheKey] = $out;

            return $out;
        }

        $out = ['ok' => true, 'body' => $body, 'error' => null];
        self::$fetchCache[$cacheKey] = $out;

        return $out;
    }
}

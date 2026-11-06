<?php

namespace MotionWork\StatamicAiAssistant\Support;

use MotionWork\StatamicAiAssistant\Services\Ai\AiProviders;

/**
 * The AI agent's web search: OpenRouter's `openrouter:web_search` server tool.
 *
 * OpenRouter runs the search itself and feeds the results to the model, so the
 * tool is only ever added to a request's `tools` list: it never comes back as
 * a tool call and is never dispatched locally. The model's sources come back
 * as `url_citation` annotations on the assistant message (citationsFrom()).
 *
 * Editors switch it on per message; the planner reads the flag off the
 * session, and the entry jobs run their generation inside during(), which is
 * how GenerationToolLoop knows without threading a flag through the generator
 * (whose API the Claude connector shares).
 */
final class WebSearch
{
    public const TOOL_TYPE = 'openrouter:web_search';

    /** @var array{enabled: bool, onCitations: (callable(array<int, array{url: string, title: string}>): void)|null}|null */
    private static ?array $scope = null;

    /**
     * Whether web search can be offered at all: enabled in config, on the
     * OpenRouter provider with its key, and not on a regional host (the EU
     * host has no search engine).
     */
    public static function available(): bool
    {
        if (! (bool) config('statamic-ai-assistant.web_search.enabled', true)) {
            return false;
        }

        if (AiProviders::active() !== AiProviders::OPENROUTER || ! AiProviders::hasCredentials()) {
            return false;
        }

        // Free mode promises no cost, but OpenRouter bills every search
        // (about $0.007–0.015), whichever model answers: off, like images.
        if (FreeModels::enabled()) {
            return false;
        }

        $host = strtolower((string) parse_url((string) config('statamic-ai-assistant.openrouter_base_url', ''), PHP_URL_HOST));

        return ! str_starts_with($host, 'eu.') && ! str_starts_with($host, 'us.');
    }

    /**
     * Whether the editor switched web search on for this session's newest
     * message (and it is still available).
     *
     * @param  array<string, mixed>  $session
     */
    public static function enabledForSession(array $session): bool
    {
        return (bool) ($session['web_search'] ?? false) && self::available();
    }

    /**
     * The `tools` entry OpenRouter runs server-side.
     *
     * @return array{type: string, parameters: array<string, mixed>}
     */
    public static function serverTool(): array
    {
        $config = (array) config('statamic-ai-assistant.web_search', []);

        $parameters = [
            'engine' => (string) ($config['engine'] ?? 'auto') ?: 'auto',
            'max_results' => max(1, min(25, (int) ($config['max_results'] ?? 5))),
            'max_total_results' => max(1, (int) ($config['max_total_results'] ?? 15)),
        ];

        foreach (['allowed_domains', 'excluded_domains'] as $key) {
            $domains = self::domainList((string) ($config[$key] ?? ''));
            if ($domains !== []) {
                $parameters[$key] = $domains;
            }
        }

        return ['type' => self::TOOL_TYPE, 'parameters' => $parameters];
    }

    /**
     * Whether a `tools` entry is a server tool (OpenRouter runs it; nothing
     * local may dispatch it).
     *
     * @param  array<string, mixed>  $tool
     */
    public static function isServerTool(array $tool): bool
    {
        return str_starts_with((string) ($tool['type'] ?? ''), 'openrouter:');
    }

    /**
     * The sources an assistant message cites: its `url_citation` annotations,
     * deduplicated by URL.
     *
     * @param  array<string, mixed>  $message
     * @return array<int, array{url: string, title: string}>
     */
    public static function citationsFrom(array $message): array
    {
        $annotations = $message['annotations'] ?? null;
        if (! is_array($annotations)) {
            return [];
        }

        $found = [];
        foreach ($annotations as $annotation) {
            if (! is_array($annotation) || ($annotation['type'] ?? '') !== 'url_citation') {
                continue;
            }

            $citation = is_array($annotation['url_citation'] ?? null) ? $annotation['url_citation'] : $annotation;
            $url = trim((string) ($citation['url'] ?? ''));
            if (! preg_match('~^https?://~i', $url)) {
                continue;
            }

            $found[] = ['url' => $url, 'title' => trim((string) ($citation['title'] ?? ''))];
        }

        return self::merge([], $found);
    }

    /**
     * An assistant message without the inline citation markers some models'
     * native search leaves in the text (OpenAI writes U+E200 "cite" U+E202
     * "turn0search0" U+E201). The sources are kept as annotations; the markers
     * would otherwise end up in replies and entry fields. Tool-call arguments
     * are cleaned too, in raw and in JSON-escaped () form.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function withoutCitationMarkers(array $message): array
    {
        if (is_string($message['content'] ?? null)) {
            $message['content'] = self::stripMarkers($message['content']);
        }

        foreach ($message['tool_calls'] ?? [] as $i => $call) {
            $arguments = $call['function']['arguments'] ?? null;
            if (is_string($arguments)) {
                $message['tool_calls'][$i]['function']['arguments'] = self::stripMarkers($arguments);
            }
        }

        return $message;
    }

    private static function stripMarkers(string $text): string
    {
        $text = (string) preg_replace('/[ \t]?\x{E200}[^\x{E201}]*\x{E201}/u', '', $text);
        $text = (string) preg_replace('/[ \t]?\\\\u[eE]200.*?\\\\u[eE]201/', '', $text);

        return (string) preg_replace('/[\x{E200}-\x{E202}]/u', '', $text);
    }

    /**
     * Citations merged into a list, one per URL (the first keeps its place; a
     * later one may fill in a missing title).
     *
     * @param  array<int, array{url: string, title: string}>  $existing
     * @param  array<int, array{url: string, title: string}>  $new
     * @return array<int, array{url: string, title: string}>
     */
    public static function merge(array $existing, array $new): array
    {
        $byUrl = [];
        foreach (array_merge($existing, $new) as $citation) {
            if (! is_array($citation) || ! is_string($citation['url'] ?? null) || $citation['url'] === '') {
                continue;
            }

            $key = self::normalizeUrl($citation['url']);
            $title = (string) ($citation['title'] ?? '');

            if (! isset($byUrl[$key])) {
                $byUrl[$key] = ['url' => $citation['url'], 'title' => $title];
            } elseif ($byUrl[$key]['title'] === '' && $title !== '') {
                $byUrl[$key]['title'] = $title;
            }
        }

        return array_values($byUrl);
    }

    /**
     * A URL in a comparable form: lowercased scheme and host, no fragment, no
     * tracking parameters (search engines append utm_source), no trailing slash.
     */
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return trim($url);
        }

        $query = '';
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
            $params = array_filter(
                $params,
                fn ($key) => ! str_starts_with(strtolower((string) $key), 'utm_'),
                ARRAY_FILTER_USE_KEY,
            );
            ksort($params);
            $query = $params !== [] ? '?'.http_build_query($params) : '';
        }

        return strtolower(($parts['scheme'] ?? 'https').'://'.$parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .rtrim($parts['path'] ?? '', '/')
            .$query;
    }

    /**
     * Run $fn with web search on or off for the generation calls it makes;
     * citations found meanwhile go to $onCitations. Restored afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  (callable(array<int, array{url: string, title: string}>): void)|null  $onCitations
     * @return T
     */
    public static function during(bool $enabled, callable $fn, ?callable $onCitations = null): mixed
    {
        $previous = self::$scope;
        self::$scope = ['enabled' => $enabled && self::available(), 'onCitations' => $onCitations];

        try {
            return $fn();
        } finally {
            self::$scope = $previous;
        }
    }

    /** Whether the current during() scope has web search on. */
    public static function activeInScope(): bool
    {
        return self::$scope !== null && self::$scope['enabled'];
    }

    /**
     * Hand citations found inside the current during() scope to its listener.
     *
     * @param  array<int, array{url: string, title: string}>  $citations
     */
    public static function reportCitations(array $citations): void
    {
        $listener = self::$scope['onCitations'] ?? null;

        if ($citations !== [] && $listener !== null) {
            $listener($citations);
        }
    }

    /** @return array<int, string> */
    private static function domainList(string $list): array
    {
        return array_values(array_filter(array_map(
            fn (string $domain) => strtolower(trim($domain)),
            explode(',', $list),
        )));
    }
}

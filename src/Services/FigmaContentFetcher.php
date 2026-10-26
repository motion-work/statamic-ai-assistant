<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Detects Figma links in a prompt, fetches them via the REST API using the
 * currently authenticated Statamic user's token, and produces a compact text
 * summary (frames, block/component names, text layers) suitable for appending
 * to the LLM's user message.
 *
 * Mirrors the augmentation contract used by PromptUrlFetcher so it can be
 * combined with it in EntryGeneratorService.
 */
class FigmaContentFetcher
{
    /** Approximate cap on characters per summary block. */
    private const MAX_CHARS_PER_FILE = 14000;

    /** Hard cap on total Figma content across all links in one prompt. */
    private const MAX_CHARS_TOTAL = 40000;

    /** Max frames (with their text layers) we'll include per file. */
    private const MAX_FRAMES = 60;

    /** Max text layers extracted per frame (keeps context focused). */
    private const MAX_TEXTS_PER_FRAME = 40;

    /** Max length of any single text-layer string. */
    private const MAX_TEXT_LEN = 240;

    /**
     * Figma REST API `depth` query and max nested frame levels in {@see appendFrameSummary}.
     * Keep them aligned so we walk the tree as deep as the API returns (no arbitrary extra cap).
     */
    private const FILE_TREE_DEPTH = 6;

    private FigmaOAuthService $oauth;

    private FigmaTokenStore $tokens;

    /**
     * Per-request memo of {@see buildAugmentation()} keyed by resolved Figma links.
     * The planner and {@see EntryGeneratorService::generateContent()} both call
     * buildAugmentation with the same underlying link(s); the entry prompt also
     * embeds the appendix, so without this we would refetch and re-log twice (or more).
     *
     * @var array<string, array{appendix: string, warnings: array<int, string>}>
     */
    private array $augmentationCache = [];

    public function __construct(FigmaOAuthService $oauth, FigmaTokenStore $tokens)
    {
        $this->oauth = $oauth;
        $this->tokens = $tokens;
    }

    /**
     * @return array{appendix: string, warnings: array<int, string>}
     */
    public function buildAugmentation(string $prompt): array
    {
        $links = $this->extractFigmaLinks($prompt);

        if ($links === []) {
            return ['appendix' => '', 'warnings' => []];
        }

        $cacheKey = $this->augmentationCacheKey($links);

        if (isset($this->augmentationCache[$cacheKey])) {
            Log::debug('Figma augmentation cache hit (same link set as earlier in this request)', [
                'cache_key' => $cacheKey,
            ]);

            return $this->augmentationCache[$cacheKey];
        }

        $token = $this->resolveAccessToken();

        if ($token === null) {
            return $this->augmentationCache[$cacheKey] = [
                'appendix' => '',
                'warnings' => [
                    __('A Figma link was detected, but Figma is not connected. Connect it in BOLD agent settings.'),
                ],
            ];
        }

        $blocks = [];
        $warnings = [];
        $totalChars = 0;

        foreach ($links as $link) {
            if ($totalChars >= self::MAX_CHARS_TOTAL) {
                $warnings[] = __('Figma context was truncated: reached the maximum amount of fetched text.');

                break;
            }

            try {
                $summary = $this->summarizeFile($token, $link['file_key'], $link['node_id'], $link['url']);
            } catch (\Throwable $e) {
                Log::warning('Figma fetch failed', [
                    'file_key' => $link['file_key'],
                    'error' => $e->getMessage(),
                ]);
                $warnings[] = __('Could not fetch Figma file :url: :reason', [
                    'url' => $link['url'],
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            $this->logRetrievedSummary($link, $summary);

            $chunk = Str::limit($summary, self::MAX_CHARS_PER_FILE, '…');
            $remaining = self::MAX_CHARS_TOTAL - $totalChars;

            if (strlen($chunk) > $remaining) {
                $chunk = Str::limit($chunk, $remaining, '…');
            }

            $totalChars += strlen($chunk);
            $blocks[] = $chunk;
        }

        if ($blocks === []) {
            return $this->augmentationCache[$cacheKey] = ['appendix' => '', 'warnings' => $warnings];
        }

        $appendix = "\n\n--- ".__('Figma design context (frames, block names, text layers)')." ---\n\n"
            .implode("\n\n---\n\n", $blocks);

        Log::info('Figma augmentation complete (what will be sent to the model)', [
            'links_fetched' => count($blocks),
            'appendix_total_characters' => strlen($appendix),
            'appendix_preview' => Str::limit($appendix, 6000, '… [preview truncated]'),
        ]);

        Log::debug('Figma augmentation full appendix', [
            'appendix' => $appendix,
        ]);

        return $this->augmentationCache[$cacheKey] = ['appendix' => $appendix, 'warnings' => $warnings];
    }

    /**
     * @param  array<int, array{url: string, file_key: string, node_id: ?string}>  $links
     */
    private function augmentationCacheKey(array $links): string
    {
        $parts = [];

        foreach ($links as $link) {
            $parts[] = ($link['file_key'] ?? '').'|'.($link['node_id'] ?? '');
        }

        sort($parts);

        return sha1(implode("\n", $parts));
    }

    /**
     * Log raw retrieved text from the Figma API for verification (preview on info, full on debug).
     *
     * @param  array{url: string, file_key: string, node_id: ?string}  $link
     */
    private function logRetrievedSummary(array $link, string $summary): void
    {
        $len = strlen($summary);

        Log::info('Figma file retrieved (raw summary before per-link size limits)', [
            'url' => $link['url'],
            'file_key' => $link['file_key'],
            'node_id' => $link['node_id'],
            'summary_characters' => $len,
            'summary_preview' => Str::limit($summary, 8000, '… [preview truncated]'),
        ]);

        Log::debug('Figma file retrieved (full summary)', [
            'url' => $link['url'],
            'file_key' => $link['file_key'],
            'node_id' => $link['node_id'],
            'summary' => $summary,
        ]);
    }

    /**
     * Extract all figma.com/file/* and figma.com/design/* URLs from text.
     *
     * @return array<int, array{url: string, file_key: string, node_id: ?string}>
     */
    public function extractFigmaLinks(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $pattern = '~\bhttps?://(?:www\.)?figma\.com/(?:file|design|proto|board)/([A-Za-z0-9]+)(?:/[^\s)>\]]*)?~i';

        if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $seen = [];
        $out = [];

        foreach ($matches[0] as $idx => $match) {
            $url = $match[0];
            $fileKey = $matches[1][$idx][0];

            // Node ID may be present as ?node-id=…
            $nodeId = null;

            $parsed = parse_url($url);

            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $query);
                if (! empty($query['node-id'])) {
                    // Figma uses both "1-2" and "1:2" interchangeably; API expects "1:2".
                    $nodeId = str_replace('-', ':', (string) $query['node-id']);
                }
            }

            $key = $fileKey.'|'.($nodeId ?? '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $out[] = [
                'url' => $url,
                'file_key' => $fileKey,
                'node_id' => $nodeId,
            ];
        }

        return $out;
    }

    /**
     * Resolve a usable access token for the current user, refreshing if needed.
     */
    private function resolveAccessToken(): ?string
    {
        $userId = $this->tokens->currentUserId();

        if ($userId === null) {
            return null;
        }

        $token = $this->tokens->getFor($userId);

        if ($token === null) {
            return null;
        }

        if ($this->tokens->isExpired($token) && $token['refresh_token'] !== '') {
            try {
                $fresh = $this->oauth->refreshAccessToken($token['refresh_token']);
                $this->tokens->saveFor($userId, [
                    'access_token' => $fresh['access_token'],
                    'expires_at' => $fresh['expires_at'],
                ]);

                return $fresh['access_token'];
            } catch (\Throwable $e) {
                Log::warning('Figma token refresh failed', ['error' => $e->getMessage()]);

                return null;
            }
        }

        return $token['access_token'];
    }

    /**
     * Fetch file contents and build a text summary.
     */
    private function summarizeFile(string $accessToken, string $fileKey, ?string $nodeId, string $url): string
    {
        $d = self::FILE_TREE_DEPTH;
        $endpoint = $nodeId
            ? "https://api.figma.com/v1/files/{$fileKey}/nodes?ids={$nodeId}&depth={$d}"
            : "https://api.figma.com/v1/files/{$fileKey}?depth={$d}";

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get($endpoint);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new \RuntimeException('Access denied — the file may be private or the token expired.');
        }

        if ($response->status() === 404) {
            throw new \RuntimeException('File not found or not accessible.');
        }

        if (! $response->ok()) {
            throw new \RuntimeException('Figma API returned '.$response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new \RuntimeException('Unexpected Figma API response.');
        }

        if ($nodeId !== null) {
            return $this->summarizeNodes($fileKey, $url, $json);
        }

        return $this->summarizeWholeFile($fileKey, $url, $json);
    }

    /**
     * Summarize a full file (/v1/files/{key}).
     *
     * @param  array<string, mixed>  $json
     */
    private function summarizeWholeFile(string $fileKey, string $url, array $json): string
    {
        $name = (string) ($json['name'] ?? 'Untitled');
        $document = $json['document'] ?? null;

        $lines = [];
        $lines[] = '## Figma file: '.$name;
        $lines[] = 'URL: '.$url;
        $lines[] = 'File key: '.$fileKey;
        $lines[] = '';

        if (! is_array($document)) {
            return implode("\n", $lines)."\n(Document structure was not available.)";
        }

        $pages = is_array($document['children'] ?? null) ? $document['children'] : [];
        $frameCount = 0;

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            if (($page['type'] ?? '') !== 'CANVAS') {
                continue;
            }

            $lines[] = '### Page: '.((string) ($page['name'] ?? 'Untitled'));

            $children = is_array($page['children'] ?? null) ? $page['children'] : [];

            foreach ($children as $frame) {
                if (! is_array($frame)) {
                    continue;
                }

                if ($frameCount >= self::MAX_FRAMES) {
                    $lines[] = '(…additional frames truncated)';
                    break 2;
                }

                $this->appendFrameSummary($lines, $frame, 0);
                $frameCount++;
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Summarize a /v1/files/{key}/nodes?ids=… response (node-scoped).
     *
     * @param  array<string, mixed>  $json
     */
    private function summarizeNodes(string $fileKey, string $url, array $json): string
    {
        $name = (string) ($json['name'] ?? 'Untitled');
        $nodes = is_array($json['nodes'] ?? null) ? $json['nodes'] : [];

        $lines = [];
        $lines[] = '## Figma file (scoped): '.$name;
        $lines[] = 'URL: '.$url;
        $lines[] = 'File key: '.$fileKey;
        $lines[] = '';

        $frameCount = 0;

        foreach ($nodes as $nodeId => $wrapper) {
            if (! is_array($wrapper) || ! isset($wrapper['document']) || ! is_array($wrapper['document'])) {
                continue;
            }

            if ($frameCount >= self::MAX_FRAMES) {
                $lines[] = '(…additional nodes truncated)';
                break;
            }

            $lines[] = '### Selected node: '.$nodeId;
            $this->appendFrameSummary($lines, $wrapper['document'], 0);
            $frameCount++;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Recursively append a frame/group/instance summary with its text layers.
     * Nested frames add indented subsections; non-frame containers just surface text.
     *
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $node
     */
    private function appendFrameSummary(array &$lines, array $node, int $depth): void
    {
        $type = (string) ($node['type'] ?? '');
        $name = (string) ($node['name'] ?? '');
        $indent = str_repeat('  ', $depth);

        $isFrame = in_array($type, ['FRAME', 'COMPONENT', 'COMPONENT_SET', 'INSTANCE', 'SECTION'], true);

        if ($isFrame) {
            $dims = '';

            if (isset($node['absoluteBoundingBox']) && is_array($node['absoluteBoundingBox'])) {
                $w = (int) ($node['absoluteBoundingBox']['width'] ?? 0);
                $h = (int) ($node['absoluteBoundingBox']['height'] ?? 0);
                if ($w > 0 && $h > 0) {
                    $dims = " ({$w}×{$h})";
                }
            }

            $label = $type === 'INSTANCE' ? 'Instance' : ucfirst(strtolower($type));

            $lines[] = "{$indent}- {$label}: \"{$name}\"{$dims}";
        }

        // Gather text layers at this level (not recursing into nested frames — those recurse below).
        $texts = $this->collectDirectTexts($node, 0, self::MAX_TEXTS_PER_FRAME);

        if ($texts !== []) {
            $lines[] = $indent.'  Texts:';
            foreach ($texts as $t) {
                $clean = trim(preg_replace('/\s+/', ' ', $t) ?? '');
                if ($clean === '') {
                    continue;
                }
                $clean = Str::limit($clean, self::MAX_TEXT_LEN, '…');
                $lines[] = $indent.'    • '.$clean;
            }
        }

        // Recurse into nested frames / components for structural context.
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];

        if ($depth < self::FILE_TREE_DEPTH) {
            foreach ($children as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $ctype = (string) ($child['type'] ?? '');
                if (in_array($ctype, ['FRAME', 'COMPONENT', 'COMPONENT_SET', 'INSTANCE', 'SECTION'], true)) {
                    $this->appendFrameSummary($lines, $child, $depth + 1);
                }
            }
        }
    }

    /**
     * Collect text layers inside a subtree, NOT crossing into nested frames
     * (frames will get their own summary block). Limits the total count.
     *
     * @param  array<string, mixed>  $node
     * @return array<int, string>
     */
    private function collectDirectTexts(array $node, int $depth, int $limit): array
    {
        $out = [];

        $walk = function (array $n, int $d) use (&$walk, &$out, $limit, $node) {
            if (count($out) >= $limit) {
                return;
            }

            $type = (string) ($n['type'] ?? '');

            // Skip sub-frames — they'll be summarized as their own blocks.
            if ($d > 0 && in_array($type, ['FRAME', 'COMPONENT', 'COMPONENT_SET', 'INSTANCE', 'SECTION'], true)) {
                return;
            }

            if ($type === 'TEXT') {
                $chars = (string) ($n['characters'] ?? '');
                if ($chars !== '') {
                    $out[] = $chars;
                }

                return;
            }

            $children = is_array($n['children'] ?? null) ? $n['children'] : [];

            foreach ($children as $c) {
                if (is_array($c)) {
                    $walk($c, $d + 1);
                }
            }
        };

        $walk($node, 0);

        return $out;
    }
}

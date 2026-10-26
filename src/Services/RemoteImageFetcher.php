<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Exposes the `save_remote_image` tool the LLM calls to copy an image from a
 * public http(s) URL into a Statamic asset container while it writes an entry.
 *
 * Each saved image is pushed onto a {@see PreferredAssetPaths} queue that the
 * EntryGeneratorAssetResolver drains into the entry's asset fields (matched by
 * container handle), so a page copied from a URL keeps its own imagery instead
 * of getting random container assets. The actual download/upload is delegated
 * to {@see AssetImageDownloader}.
 */
class RemoteImageFetcher
{
    public function __construct(private AssetImageDownloader $downloader) {}

    /**
     * OpenAI-style tool definition exposed to the entry generator's tool loop.
     *
     * @return array{type: string, function: array<string, mixed>}
     */
    public function chatToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'save_remote_image',
                'description' => 'Downloads an image from a public http(s) URL and attaches it to the entry being written. '
                    .'Use it to copy a source page\'s real imagery: pass image URLs you saw in fetch_page_content results. '
                    .'Save the most important image (hero / lead) first — images fill the entry\'s image fields in the order you save them. '
                    .'Do NOT put image URLs into text or rich-text fields; saved images are assigned to image fields automatically.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Full absolute URL of the image file (https preferred), e.g. https://example.com/img/hero.jpg.',
                        ],
                        'reason' => [
                            'type' => 'string',
                            'description' => 'Why this image belongs on the entry (e.g. "hero image of the article", "product photo"). Keeps the action traceable.',
                        ],
                    ],
                    'required' => ['url', 'reason'],
                ],
            ],
        ];
    }

    /**
     * Run one save_remote_image tool call: parse arguments, download the image,
     * store it in the target container and register it on $sink. Returns the
     * JSON-encoded result the model should see in its tool message.
     *
     * @param  PreferredAssetPaths  $sink  Queue the saved image is appended to (consumed by the asset resolver)
     * @param  string|null  $containerHint  Container the entry's asset fields point at (auto-detected from the blueprint)
     * @param  callable(string): void|null  $onStreamToken  Optional CP drawer notifier
     * @param  array<int, string>  $warningsOut  Per-request warning sink
     */
    public function executeChatTool(string $argumentsJson, PreferredAssetPaths $sink, ?string $containerHint, ?callable $onStreamToken, array &$warningsOut): string
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('[entry-gen-tool] invalid save_remote_image arguments JSON', [
                'error' => $e->getMessage(),
                'arguments_excerpt' => Str::limit($argumentsJson, 300),
            ]);

            return $this->fail('invalid_arguments_json', '', '');
        }

        if (! is_array($args)) {
            return $this->fail('invalid_arguments', '', '');
        }

        $url = isset($args['url']) && is_string($args['url']) ? trim($args['url']) : '';
        $reason = isset($args['reason']) && is_string($args['reason']) ? trim($args['reason']) : '';

        if ($url === '' || $reason === '') {
            return $this->fail('url_and_reason_required', $url, $reason);
        }

        if (! $this->isFetchableUrl($url)) {
            return $this->fail('url_not_allowed', $url, $reason);
        }

        if ($onStreamToken) {
            $onStreamToken("\n\n[".__('Saving image: :url', ['url' => $url])." — {$reason}]\n\n");
        }

        // Save into the entry's own asset-field container (auto-detected from the
        // blueprint) so the resolver can attach the image; resolveContainer falls
        // back to the configured / first container only if that is null.
        $container = $this->downloader->resolveContainer($containerHint);
        if ($container === null) {
            $warningsOut[] = __('Could not save image :url: no asset container is configured.', ['url' => $url]);

            return $this->fail('no_asset_container', $url, $reason);
        }

        $folder = (string) config('statamic-ai-assistant.image_fetch.folder', 'bold-agent-fetched');
        $timeout = max(1, (int) config('statamic-ai-assistant.image_fetch.timeout', 20));
        $maxBytes = max(1024, (int) config('statamic-ai-assistant.image_fetch.max_bytes', 10 * 1024 * 1024));

        $saved = $this->downloader->save($container, $folder, $url, $timeout, $maxBytes);

        if ($saved === null) {
            Log::warning('[entry-gen-tool] save_remote_image failed', ['url' => $url, 'reason' => $reason]);
            $warningsOut[] = __('Could not save image :url: download failed or it was not a usable image.', ['url' => $url]);

            return $this->fail('image_download_failed', $url, $reason);
        }

        $containerHandle = (string) $container->handle();
        $sink->add($containerHandle, $saved['path']);

        Log::info('[entry-gen-tool] image saved', [
            'url' => $url,
            'reason' => $reason,
            'container' => $containerHandle,
            'path' => $saved['path'],
        ]);

        return json_encode([
            'ok' => true,
            'url' => $url,
            'reason_echo' => $reason,
            'container' => $containerHandle,
            'path' => $saved['path'],
            'public_url' => $saved['public_url'],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function fail(string $error, string $url, string $reason): string
    {
        return json_encode([
            'ok' => false,
            'error' => $error,
            'url' => $url,
            'reason_echo' => $reason,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Light SSRF guard: only public http(s) hosts. Mirrors PromptUrlFetcher's
     * host policy — the LLM picks these URLs from fetched page content, so the
     * server must not be coaxed into hitting localhost or private ranges.
     */
    private function isFetchableUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}

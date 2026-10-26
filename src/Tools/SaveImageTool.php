<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Services\RemoteImageFetcher;
use MotionWork\StatamicAiAssistant\Support\HostAllowlist;
use Illuminate\Support\Str;

/**
 * ChatTool adapter over RemoteImageFetcher's save_remote_image capability. Pulls
 * the asset sink + container hint from the ToolContext so its execution fits the
 * uniform handle() signature. Only registered on the create path (where saved
 * images are auto-attached), so its presence is decided at wiring time.
 *
 * Image URLs obey the SAME provenance rule as page fetching: the model may only
 * save an image whose host the user referenced, or whose exact URL appeared in a
 * page fetched this run. This stops it from inventing an image URL from memory
 * (e.g. a Wikimedia link the user never gave) — a fabricated download that would
 * otherwise fail slowly and waste the whole per-image timeout budget.
 *
 * @param  array<int, string>  $allowedHosts  Hosts the user referenced (lowercased).
 */
class SaveImageTool implements ChatTool
{
    public function __construct(
        private RemoteImageFetcher $fetcher,
        private array $allowedHosts = [],
        private bool $restrict = true,
    ) {}

    public function name(): string
    {
        return 'save_remote_image';
    }

    public function definition(): array
    {
        return $this->fetcher->chatToolDefinition();
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        $args = json_decode($argumentsJson, true);
        $url = (is_array($args) && isset($args['url']) && is_string($args['url'])) ? trim($args['url']) : '';

        if ($this->restrict && ! $this->isImageAllowed($url, $context)) {
            $context->addWarning((string) __('Skipped image :url — the agent only saves images from pages you provided.', [
                'url' => Str::limit($url !== '' ? $url : '(none)', 80),
            ]));

            return [
                'ok' => false,
                'error' => 'image_url_not_allowed',
                'url' => $url,
                'message' => $this->allowedHosts === []
                    ? 'Image saving is disabled: the user provided no source URL and no page was fetched. '
                        .'Do NOT invent or guess image URLs. Leave image fields empty — they may be filled from the asset library instead.'
                    : 'Only images from the page(s) the user provided (or found within them) may be saved. '
                        .'Do NOT invent, guess, or recall image URLs from memory.',
            ];
        }

        if ($url !== '') {
            $short = preg_replace('~^https?://~i', '', $url);
            $context->reportActivity((string) __('Saving image :url', ['url' => Str::limit((string) $short, 50)]));
        }

        $warnings = [];
        $json = $this->fetcher->executeChatTool(
            $argumentsJson,
            $context->imageSink ?? new PreferredAssetPaths,
            $context->imageContainerHint,
            $context->onStreamToken(),
            $warnings,
        );
        foreach ($warnings as $w) {
            $context->addWarning((string) $w);
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'tool_error'];
    }

    public function maxCalls(): ?int
    {
        return max(0, (int) config('statamic-ai-assistant.image_fetch.max_images', 30));
    }

    /**
     * Hybrid provenance check: allow an image whose host the user referenced
     * (incl. subdomains, so a page's own CDN passes) OR whose exact URL appeared
     * in a page fetched this run. Everything else is a memory-invented URL.
     */
    private function isImageAllowed(string $url, ToolContext $context): bool
    {
        if ($url === '') {
            return false;
        }

        $hosts = array_merge($this->allowedHosts, $context->fetchedHosts());

        return HostAllowlist::matches($url, $hosts) || $context->sawFetchedUrl($url);
    }
}

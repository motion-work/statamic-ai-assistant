<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Facades\AssetContainer;

/**
 * Downloads a single remote image and stores it in a Statamic asset container.
 *
 * Single source of truth for "pull an image URL into an asset" — used by the
 * save_remote_image LLM tool ({@see RemoteImageFetcher}) so every download goes
 * through one battle-tested upload path with the same validation, idempotency
 * and naming.
 *
 * Filenames are deterministic ({slug}-{8 chars of url md5}.{ext}) so a retried
 * job sees the existing file on disk and reuses it instead of re-uploading.
 */
class AssetImageDownloader
{
    /**
     * Resolve a usable container: the given handle first, then the configured
     * `image_fetch.asset_container`, then the first available container.
     * Returns null only when the site has no asset containers at all.
     */
    public function resolveContainer(?string $preferredHandle = null)
    {
        $candidates = [
            $preferredHandle,
            config('statamic-ai-assistant.image_fetch.asset_container'),
        ];

        foreach ($candidates as $handle) {
            if (is_string($handle) && $handle !== '') {
                $container = AssetContainer::find($handle);

                if ($container) {
                    return $container;
                }
            }
        }

        return AssetContainer::all()->first();
    }

    /**
     * Download $url and store it under $folder in $container.
     *
     * Idempotent: a second call with the same URL reuses the existing file.
     * Returns null on any failure (transport error, non-image content type,
     * oversize body) so callers can simply skip the image.
     *
     * @return array{path: string, public_url: ?string}|null
     */
    public function save($container, string $folder, string $url, int $timeout, int $maxBytes): ?array
    {
        $folder = trim($folder, '/');
        if ($folder === '') {
            $folder = 'bold-agent-fetched';
        }

        try {
            // Provisional path so we can short-circuit on retry without a HEAD.
            // Filename is fully URL-derived, so this is stable across attempts.
            $filename = $this->slugFilename($url, 'application/octet-stream');
            $tentativePath = $folder.'/'.$filename;
            if ($container->disk()->exists($tentativePath)) {
                $existing = $container->makeAsset($tentativePath);

                return ['path' => $tentativePath, 'public_url' => $this->safeUrl($existing)];
            }

            $response = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'StatamicAiAssistant/1.0'])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $contentType = (string) ($response->header('Content-Type') ?: '');
            if (! str_starts_with(strtolower($contentType), 'image/')) {
                return null;
            }

            $body = (string) $response->body();
            if ($body === '' || strlen($body) > $maxBytes) {
                return null;
            }

            // Re-derive filename now that we know the real content type, in case
            // the URL had no extension.
            $filename = $this->slugFilename($url, $contentType);
            $path = $folder.'/'.$filename;

            // Race window: another worker may have written this exact path
            // between the early check and here. upload() overwrites idempotently
            // (same URL → same bytes), so the race is benign.
            $tmp = tempnam(sys_get_temp_dir(), 'bold-mig-');
            if ($tmp === false) {
                return null;
            }
            file_put_contents($tmp, $body);

            $upload = new UploadedFile($tmp, basename($filename), $contentType, null, true);
            $asset = $container->makeAsset($path);
            $asset->upload($upload);

            @unlink($tmp);

            return ['path' => $path, 'public_url' => $this->safeUrl($asset)];
        } catch (\Throwable $e) {
            Log::notice('Asset image download failed', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function slugFilename(string $url, string $contentType): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $name = pathinfo($path, PATHINFO_FILENAME) ?: 'image';
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '' || strlen($ext) > 5) {
            $ext = match (true) {
                str_contains($contentType, 'jpeg') => 'jpg',
                str_contains($contentType, 'png') => 'png',
                str_contains($contentType, 'webp') => 'webp',
                str_contains($contentType, 'gif') => 'gif',
                str_contains($contentType, 'svg') => 'svg',
                default => 'bin',
            };
        }
        $base = Str::slug(Str::limit($name, 80, ''));
        if ($base === '') {
            $base = 'image';
        }
        $hash = substr(md5($url), 0, 8);

        return $base.'-'.$hash.'.'.$ext;
    }

    private function safeUrl($asset): ?string
    {
        try {
            $url = $asset->url();

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Statamic\Facades\AssetContainer;

/**
 * Deterministically resolves "@asset:container::path" / "@folder:container::path"
 * mentions from chat prompts into an authoritative context block, the same way
 * PromptUrlFetcher pre-fetches URLs. Without this, models answer questions about
 * asset folders from the entries catalog (collections often share names with
 * folders — "apartments" the collection vs. assets::apartments the folder).
 */
class PromptAssetMentionResolver
{
    private const MAX_REFS = 8;

    private const MAX_FILES_LISTED = 40;

    /** Matches the exact token format the mention picker inserts. */
    private const MENTION_PATTERN = '/@(asset|folder):([a-z0-9_-]+)::([^\s"\'<>`]+)/iu';

    /** Max images auto-queued as preferred imagery from a mentioned folder. */
    private const MAX_PREFERRED_PER_FOLDER = 12;

    /**
     * Scan one or more prompt texts (oldest → newest) and resolve every unique
     * mention. `appendix` covers ALL texts; `preferred` ({container, path}
     * pairs for the asset resolver) is computed from the NEWEST text only —
     * assets referenced in the current request are what the user wants the
     * entries of THIS turn to use, older refs must not leak into new entries.
     *
     * @param  array<int, string>  $texts
     * @return array{appendix: string, preferred: array<int, array{container: string, path: string}>}
     */
    public function resolve(array $texts): array
    {
        $refs = [];
        $newestKeys = [];
        $lastIdx = count($texts) - 1;

        foreach ($texts as $i => $text) {
            if (! is_string($text) || $text === '') {
                continue;
            }

            preg_match_all(self::MENTION_PATTERN, $text, $matches, PREG_SET_ORDER);

            foreach ($matches as $m) {
                $kind = strtolower($m[1]);
                $container = $m[2];
                // Refs can sit at the end of a sentence — strip trailing punctuation.
                $path = rtrim($m[3], '.,;:!?)');
                $key = $kind.':'.$container.'::'.$path;
                $refs[$key] = [$kind, $container, $path];

                if ($i === $lastIdx) {
                    $newestKeys[$key] = true;
                }
            }
        }

        if ($refs === []) {
            return ['appendix' => '', 'preferred' => []];
        }

        $lines = [];
        $preferred = [];

        foreach (array_slice(array_keys($refs), 0, self::MAX_REFS) as $key) {
            [$kind, $containerHandle, $path] = $refs[$key];
            $container = AssetContainer::findByHandle($containerHandle);

            if (! $container) {
                $lines[] = "- {$kind} {$containerHandle}::{$path} — container \"{$containerHandle}\" not found.";

                continue;
            }

            $lines[] = $kind === 'folder'
                ? $this->folderLine($container, $path)
                : $this->assetLine($container, $path);

            if (isset($newestKeys[$key])) {
                foreach ($this->preferredPairs($kind, $container, $path) as $pair) {
                    $preferred[] = $pair;
                }
            }
        }

        return [
            'appendix' => "\n\nREFERENCED ASSETS (resolved from the asset library — authoritative; these are FILES, not collections or entries; do not re-verify them with list_assets; "
                ."files referenced in the newest message are automatically queued as preferred imagery for any entries created this turn):\n"
                .implode("\n", $lines),
            'preferred' => $preferred,
        ];
    }

    /**
     * {container, path} pairs to auto-queue as preferred imagery: the asset
     * itself, or a mentioned folder's images. This is what makes "use this
     * image everywhere @asset:…" deterministic — entry generation consumes the
     * queue for every asset field instead of relying on the model remembering
     * to call use_assets.
     *
     * @return array<int, array{container: string, path: string}>
     */
    private function preferredPairs(string $kind, mixed $container, string $path): array
    {
        $handle = (string) $container->handle();

        if ($kind === 'asset') {
            $asset = $container->asset(trim($path, '/'));

            return $asset && $asset->isImage()
                ? [['container' => $handle, 'path' => (string) $asset->path()]]
                : [];
        }

        $folder = trim($path, '/');

        return $container->assets($folder !== '' ? $folder : '/', false)
            ->filter(fn ($a) => $a->isImage())
            ->take(self::MAX_PREFERRED_PER_FOLDER)
            ->map(fn ($a) => ['container' => $handle, 'path' => (string) $a->path()])
            ->values()
            ->all();
    }

    private function folderLine(mixed $container, string $folder): string
    {
        $folder = trim($folder, '/');
        $assets = $container->assets($folder !== '' ? $folder : '/', false);
        $total = $assets->count();
        $images = $assets->filter(fn ($a) => $a->isImage())->count();

        $files = $assets->take(self::MAX_FILES_LISTED)
            ->map(fn ($a) => $a->basename())
            ->implode(', ');

        $line = "- folder {$container->handle()}::{$folder} — {$total} files ({$images} images)";

        if ($files !== '') {
            $line .= ': '.$files;
            if ($total > self::MAX_FILES_LISTED) {
                $line .= ', … ('.($total - self::MAX_FILES_LISTED).' more)';
            }
        }

        return $line;
    }

    private function assetLine(mixed $container, string $path): string
    {
        $asset = $container->asset(trim($path, '/'));

        if (! $asset) {
            return "- asset {$container->handle()}::{$path} — not found in this container.";
        }

        $parts = [$asset->isImage() ? 'image' : 'file'];

        // Non-empty blueprint meta values (alt texts etc.) so read-only questions
        // about the asset are answerable without any tool call.
        foreach ($container->blueprint()?->fields()->all()->keys()->all() ?? [] as $handle) {
            $value = $asset->get($handle);
            if (is_string($value) && trim($value) !== '') {
                $parts[] = $handle.': "'.$value.'"';
            }
        }

        return "- asset {$container->handle()}::{$path} — ".implode('; ', $parts);
    }
}

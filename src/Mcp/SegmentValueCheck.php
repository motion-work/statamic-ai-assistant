<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Whether a new value fits an editable value segment (toggle, choice, link,
 * assets) and the form it is stored in.
 */
final class SegmentValueCheck
{
    /**
     * A toggle takes true or false, a choice field one of its options (a list
     * of them when it allows several), a link a URL, mailto:, tel:, a /path,
     * a #anchor or entry::<id> of an existing entry, an assets field (images,
     * also videos or files) the path of an asset in its container (a list
     * when it takes several). Null clears a choice, a link or an asset.
     *
     * @param  array<string, mixed>  $segment
     * @return array{value: mixed}|array{error: string}
     */
    public function checkValue(array $segment, mixed $new): array
    {
        if ($segment['kind'] === 'toggle') {
            $bool = is_bool($new) ? $new : filter_var($new, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $bool === null ? ['error' => 'A toggle takes true or false.'] : ['value' => $bool];
        }

        if ($new === null || $new === '' || $new === []) {
            return ['value' => null];
        }

        if ($segment['kind'] === 'asset') {
            return $this->checkImages($segment, $new);
        }

        if ($segment['kind'] === 'choice') {
            $picked = $new;
            if ($segment['multiple']) {
                $picked = is_array($new) ? array_values($new) : [$new];
            }
            if (! $segment['multiple'] && ! is_scalar($picked)) {
                return ['error' => 'This field takes one option.'];
            }
            foreach ((array) $picked as $option) {
                if (! is_scalar($option) || (! $segment['custom_values_allowed'] && ! array_key_exists((string) $option, $segment['options']))) {
                    return ['error' => 'Pick from the options: '.implode(', ', array_keys($segment['options'])).'.'];
                }
            }

            return ['value' => is_array($picked) ? array_map('strval', $picked) : (string) $picked];
        }

        if (! is_string($new)) {
            return ['error' => 'A link is a string.'];
        }
        $link = trim($new);
        if (str_starts_with($link, 'entry::')) {
            return EntryFacade::find(substr($link, 7))
                ? ['value' => $link]
                : ['error' => 'No entry has id '.substr($link, 7).'. Use an id from find_entries.'];
        }
        if (preg_match('~^(https?://\S+|mailto:\S+|tel:[+\d\s()/-]+|/\S*|#\S+)$~i', $link) !== 1) {
            return ['error' => 'A link is a full URL, mailto:, tel:, a /path, a #anchor or entry::<id>.'];
        }

        return ['value' => $link];
    }

    /**
     * Paths may carry the container prefix the Control Panel shows
     * ("assets::hero/bild.jpg"). Stored as the field stores them: one path,
     * or a list when the field takes several.
     *
     * @param  array<string, mixed>  $segment
     * @return array{value: mixed}|array{error: string}
     */
    private function checkImages(array $segment, mixed $new): array
    {
        $container = is_string($segment['container']) ? AssetContainer::find($segment['container']) : null;
        if (! $container) {
            return ['error' => 'This assets field has no single asset container, so it cannot be set here.'];
        }
        $paths = is_array($new) ? array_values($new) : [$new];
        if (! $segment['multiple'] && count($paths) > 1) {
            return ['error' => 'This field takes one asset.'];
        }

        $checked = [];
        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                return ['error' => 'An asset is its path in the container, such as from check_image_upload.'];
            }
            $path = ltrim(trim($path), '/');
            if (str_starts_with($path, $container->handle().'::')) {
                $path = substr($path, strlen($container->handle()) + 2);
            }
            if (! $container->disk()->exists($path)) {
                return ['error' => 'No asset '.$path.' in the '.$container->handle().' container. Upload it with prepare_image_upload first.'];
            }
            if ($segment['restrict_to_folder'] && $segment['folder'] !== '' && ! str_starts_with($path, $segment['folder'].'/')) {
                return ['error' => 'This field only takes assets from the folder '.$segment['folder'].'. Upload into that folder.'];
            }
            $checked[] = $path;
        }

        return ['value' => $segment['multiple'] ? $checked : $checked[0]];
    }
}

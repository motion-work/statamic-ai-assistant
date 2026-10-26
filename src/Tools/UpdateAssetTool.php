<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use Statamic\Facades\Asset;

/**
 * Updates an asset's metadata (alt text, captions, custom fields). Values are
 * validated against the container's asset blueprint so the model can only
 * write real handles — sites that model per-language alt as separate handles
 * (alt, alt_text_fr, …) are covered without any special-casing.
 */
class UpdateAssetTool implements ChatTool
{
    public function name(): string
    {
        return 'update_asset';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_asset',
                'description' => 'Update an existing asset\'s METADATA (alt text, caption, custom fields — never the file itself). '
                    .'Pass the "container::path" reference and a values object keyed by the container\'s meta field handles '
                    .'(list_assets returns them as meta_fields). Sites often model per-language alt text as separate handles '
                    .'(e.g. alt, alt_text_fr, alt_text_en) — write each language to its own handle, translating yourself.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'ref' => ['type' => 'string', 'description' => 'Asset reference "container::path" (from list_assets).'],
                        'values' => ['type' => 'object', 'description' => 'Meta values keyed by field handle, e.g. {"alt": "…", "alt_text_fr": "…"}.'],
                    ],
                    'required' => ['ref', 'values'],
                ],
            ],
        ];
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        $ref = isset($args['ref']) && is_string($args['ref']) ? trim($args['ref']) : '';
        $asset = $ref !== '' ? Asset::find($ref) : null;

        if (! $asset) {
            return ['ok' => false, 'error' => "Asset \"{$ref}\" not found. Use exact \"container::path\" references from list_assets."];
        }

        $values = is_array($args['values'] ?? null) ? $args['values'] : [];
        if ($values === []) {
            return ['ok' => false, 'error' => 'Provide at least one meta value.'];
        }

        $allowed = $asset->container()->blueprint()?->fields()->all()->keys()->all() ?? [];

        $applied = [];
        $rejected = [];
        $unchanged = [];

        foreach ($values as $handle => $value) {
            if (! is_string($handle) || ! in_array($handle, $allowed, true)) {
                $rejected[] = (string) $handle;

                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                $rejected[] = $handle;

                continue;
            }

            // Writing the value a field already holds (including "" over an
            // empty field) is a no-op — never save it or report it as a change.
            $current = $asset->get($handle);
            if ((string) ($current ?? '') === (string) ($value ?? '')) {
                $unchanged[] = $handle;

                continue;
            }

            $asset->set($handle, $value);
            $applied[] = $handle;
        }

        if ($applied === [] && $unchanged !== []) {
            return [
                'ok' => true,
                'updated' => false,
                'ref' => $ref,
                'unchanged' => $unchanged,
                'note' => 'Every provided value matches what the asset already holds — nothing was written. To read current metadata, use list_assets.',
            ];
        }

        if ($applied === []) {
            return [
                'ok' => false,
                'error' => 'No valid meta fields. Available handles: '.($allowed !== [] ? implode(', ', $allowed) : '(none — the container has no asset blueprint)'),
                'rejected' => $rejected,
            ];
        }

        $context->reportActivity((string) __('Updating asset metadata: :file', ['file' => $asset->basename()]));

        $asset->save();

        $result = ['ok' => true, 'updated' => true, 'ref' => $ref, 'applied' => $applied];

        if ($unchanged !== []) {
            $result['unchanged'] = $unchanged;
        }

        if ($rejected !== []) {
            $result['rejected'] = $rejected;
            $result['hint'] = 'Rejected handles are not in the asset blueprint. Available: '.implode(', ', $allowed);
        }

        return $result;
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_asset_writes', 40));
    }
}

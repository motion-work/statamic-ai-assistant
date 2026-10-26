<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use Statamic\Facades\Asset;

/**
 * Marks EXISTING assets as the preferred imagery for the entries being created
 * or updated in this run — "for this page use the images from @folder:…".
 * Pushes into the same PreferredAssetPaths sink the save_remote_image tool
 * uses, so the generator's existing asset auto-fill consumes them with zero
 * new plumbing.
 */
class UseAssetsTool implements ChatTool
{
    public function name(): string
    {
        return 'use_assets';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'use_assets',
                'description' => 'Mark existing assets as the PREFERRED images for the entries of this request. '
                    .'Pass exact "container::path" references (from list_assets). The generator fills image fields '
                    .'from these first, in the given order. Use when the user points at existing imagery '
                    .'(e.g. "use assets from @folder:…") instead of fetching remote images.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'refs' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Asset references "container::path", best first.',
                        ],
                    ],
                    'required' => ['refs'],
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

        $refs = is_array($args['refs'] ?? null) ? array_values(array_filter($args['refs'], 'is_string')) : [];
        if ($refs === []) {
            return ['ok' => false, 'error' => 'Provide at least one "container::path" reference (see list_assets).'];
        }

        $sink = $context->imageSink;
        if ($sink === null) {
            return ['ok' => false, 'error' => 'Asset selection is not available in this context.'];
        }

        $accepted = [];
        $unknown = [];

        foreach (array_slice($refs, 0, 50) as $ref) {
            $asset = Asset::find($ref);

            if (! $asset) {
                $unknown[] = $ref;

                continue;
            }

            $sink->add($asset->containerHandle(), $asset->path());
            $accepted[] = $ref;
        }

        if ($accepted !== []) {
            $context->reportActivity((string) __(':n existing assets selected as imagery', ['n' => count($accepted)]));
        }

        $result = ['ok' => $accepted !== [], 'accepted' => $accepted];

        if ($unknown !== []) {
            $result['unknown'] = $unknown;
            $result['hint'] = 'Unknown references — use exact "container::path" values from list_assets.';
        }

        return $result;
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_cms_reads', 12));
    }
}

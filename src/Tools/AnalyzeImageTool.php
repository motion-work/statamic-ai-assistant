<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use Statamic\Facades\Asset;

/**
 * Describes what an image asset actually shows, via the provider's vision
 * model. Composable by design: the agent analyzes once, then writes alt texts
 * in as many languages as needed itself (it is a language model) — no
 * dedicated "generate alt" pipeline. Registered only when a vision model is
 * configured (see infomaniak_vision_model).
 */
class AnalyzeImageTool implements ChatTool
{
    /** Refuse to inline images beyond this size (vision payload limit). */
    private const MAX_IMAGE_BYTES = 6_000_000;

    public function __construct(private AbstractAiService $aiService) {}

    public function name(): string
    {
        return 'analyze_image';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'analyze_image',
                'description' => 'Look at an image asset and describe what it shows (subjects, setting, mood, visible text). '
                    .'Use it before writing alt texts or choosing between images. Pass the "container::path" reference. '
                    .'You translate the description into the languages you need yourself.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'ref' => ['type' => 'string', 'description' => 'Asset reference "container::path" (from list_assets).'],
                        'question' => ['type' => 'string', 'description' => 'Optional specific question about the image (defaults to a general description).'],
                    ],
                    'required' => ['ref'],
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

        if (! $asset->isImage()) {
            return ['ok' => false, 'error' => "Asset \"{$ref}\" is not an image."];
        }

        if ((int) $asset->size() > self::MAX_IMAGE_BYTES) {
            return ['ok' => false, 'error' => 'Image is too large to analyze ('.round($asset->size() / 1_000_000, 1).'MB).'];
        }

        $context->reportActivity((string) __('Analyzing image :file', ['file' => $asset->basename()]));

        $contents = $asset->contents();
        if (! is_string($contents) || $contents === '') {
            return ['ok' => false, 'error' => 'Could not read the image file.'];
        }

        $mime = (string) ($asset->mimeType() ?? 'image/jpeg');
        $dataUrl = 'data:'.$mime.';base64,'.base64_encode($contents);

        $question = isset($args['question']) && is_string($args['question']) && trim($args['question']) !== ''
            ? trim($args['question'])
            : 'Describe this image concisely for use as a basis for alt text: main subject, setting, mood, any visible text. 2-4 sentences.';

        try {
            $description = $this->aiService->describeImage($dataUrl, $question);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'image_analysis_failed', 'detail' => $e->getMessage()];
        }

        return ['ok' => true, 'ref' => $ref, 'description' => $description];
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_image_analyses', 40));
    }
}

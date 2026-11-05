<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviderException;
use MotionWork\StatamicAiAssistant\Services\Ai\GeneratesImages;
use MotionWork\StatamicAiAssistant\Services\GeneratedImageStore;
use MotionWork\StatamicAiAssistant\Support\ImageGeneration;

/**
 * generate_image: creates one image with the `image` profile's models and saves
 * it as an asset of the entry being generated. Offered only inside an
 * ImageGeneration scope (the site switch is on, the provider is OpenRouter).
 *
 * The saved image joins the scope's preferred-asset queue: on a new entry the
 * generator attaches it to an image field; on an update the model sets the
 * asset field it wants changed to the returned path.
 */
class GenerateImageTool implements ChatTool
{
    private const ASPECT_RATIOS = ['1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3', '21:9'];

    public function __construct(
        private GeneratesImages $images,
        private GeneratedImageStore $store,
    ) {}

    public function name(): string
    {
        return 'generate_image';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'generate_image',
                'description' => 'Generate ONE new image with an image model and save it to the asset library for this entry. '
                    .'Use ONLY when the user explicitly asked you to generate or create an image. Never use it to fill empty image fields on your own; '
                    .'prefer existing assets and images the user provided. Never depict a real, identifiable person, a brand logo or a copyrighted character. '
                    .'Write the prompt in English: subject, setting, style, light; no text in the image unless asked.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'What the image shows, in English, 1–3 sentences.'],
                        'aspect_ratio' => ['type' => 'string', 'enum' => self::ASPECT_RATIOS, 'description' => 'Default 16:9 (hero/header); 1:1 for teasers or avatars.'],
                        'field' => ['type' => 'string', 'description' => 'Handle of the asset field the image is for, when you know it.'],
                        'alt_hint' => ['type' => 'string', 'description' => 'What the image is for on the page, to help write its alt text.'],
                    ],
                    'required' => ['prompt'],
                ],
            ],
        ];
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        $args = ToolArguments::decode($argumentsJson, $this->name());
        $prompt = trim((string) ($args['prompt'] ?? ''));
        $scope = ImageGeneration::scope();

        if ($args === null || $prompt === '') {
            return ['ok' => false, 'error' => 'Pass a non-empty "prompt" describing the image.'];
        }
        if ($scope === null) {
            return ['ok' => false, 'error' => 'Image generation is not available for this entry.'];
        }

        $context->reportActivity((string) __('Generating image…'));

        try {
            $image = $this->images->generateImage($prompt, $this->options($args));
            $saved = $this->store->save($image, $scope['container'], $prompt, (string) ($args['alt_hint'] ?? ''));
        } catch (\Throwable $e) {
            return $this->failed($e, $context);
        }

        if ($saved === null) {
            return ['ok' => false, 'error' => 'The site has no asset container to save the image in.'];
        }

        $scope['sink']->add($saved['container'], $saved['path']);
        $context->reportActivity((string) __('Image saved: :path', ['path' => Str::limit($saved['path'], 60)]));

        return $this->saved($saved, (string) ($args['field'] ?? ''));
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.image_generation.max_per_entry', 4));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{aspect_ratio?: string}
     */
    private function options(array $args): array
    {
        $ratio = (string) ($args['aspect_ratio'] ?? '');

        return in_array($ratio, self::ASPECT_RATIOS, true) ? ['aspect_ratio' => $ratio] : [];
    }

    /**
     * @param  array{container: string, path: string, public_url: ?string}  $saved
     * @return array<string, mixed>
     */
    private function saved(array $saved, string $field): array
    {
        return [
            'ok' => true,
            'container' => $saved['container'],
            'path' => $saved['path'],
            'field' => $field !== '' ? $field : null,
            'note' => 'Saved. On a NEW entry it is attached to the entry\'s image field automatically. '
                .'When UPDATING an entry, set the asset field you want to change to exactly this path.',
        ];
    }

    /**
     * Budget and credit refusals reach the editor as they are; anything else
     * gets a short warning, and the model carries on without the image.
     *
     * @return array<string, mixed>
     */
    private function failed(\Throwable $e, ToolContext $context): array
    {
        Log::warning('[entry-gen-tool] generate_image failed', ['message' => $e->getMessage()]);

        $message = $e instanceof AiProviderException
            ? $e->getMessage()
            : (string) __('The image could not be generated: :message', ['message' => Str::limit($e->getMessage(), 160)]);
        $context->addWarning($message);

        return ['ok' => false, 'error' => 'image_generation_failed', 'message' => $message.' Do not retry; continue without this image.'];
    }
}

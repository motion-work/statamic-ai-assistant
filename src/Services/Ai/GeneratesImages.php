<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

/**
 * A provider that can generate images (OpenRouter's images endpoint). Kept
 * apart from AbstractAiService, which every provider implements: whether the
 * agent may use it is ImageGeneration::enabled().
 */
interface GeneratesImages
{
    /** Whether image models are configured (the `image` profile). */
    public function supportsImageGeneration(): bool;

    /**
     * Generate one image from a text prompt.
     *
     * @param  array{aspect_ratio?: string}  $options
     */
    public function generateImage(string $prompt, array $options = []): GeneratedImage;
}

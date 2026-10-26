<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Site;

/**
 * Generates a concise alt text for an image the agent just downloaded, so the
 * asset is saved WITH alt from the start. This keeps Statamic's first
 * AssetSaved event complete — accessibility addons that react to assets
 * without alt (missing-alt caches, audits, ...) never see an alt-less save.
 *
 * One quick LLM call per image: the provider's vision model when available
 * (it sees the actual bytes), otherwise a plain text completion from the
 * planner's stated reason for saving the image. Strictly best-effort — any
 * failure returns null and the image is saved without alt, never blocked.
 */
class ImageAltTextGenerator
{
    /** Alt texts beyond this length stop being alt texts. */
    private const MAX_LENGTH = 160;

    /** Vision payloads above this size are skipped (latency + token cost). */
    private const MAX_VISION_BYTES = 6_000_000;

    public function __construct(private AbstractAiService $aiService) {}

    public function enabled(): bool
    {
        return (bool) config('statamic-ai-assistant.image_fetch.generate_alt', true);
    }

    /**
     * Alt text for freshly downloaded image bytes.
     *
     * @param  string  $bytes  Raw image body (already validated as image/*)
     * @param  string  $contentType  e.g. "image/jpeg"
     * @param  string  $context  Why the image is being saved (the tool's "reason")
     */
    public function forImageBytes(string $bytes, string $contentType, string $context): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            if ($this->aiService->supportsVision() && $bytes !== '' && strlen($bytes) <= self::MAX_VISION_BYTES) {
                $dataUrl = 'data:'.$contentType.';base64,'.base64_encode($bytes);

                return $this->sanitize($this->aiService->describeImage($dataUrl, $this->visionPrompt($context)));
            }

            if (trim($context) === '') {
                return null;
            }

            return $this->sanitize($this->aiService->generateContentFromPrompt($this->textPrompt($context)));
        } catch (\Throwable $e) {
            Log::notice('[entry-gen-tool] alt text generation failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function visionPrompt(string $context): string
    {
        $prompt = 'Write a concise alt text for this image (max 125 characters, plain text, no quotes, no "image of" prefix). '
            .'Describe the main subject factually for screen-reader users.'
            .$this->languageInstruction();

        if (trim($context) !== '') {
            $prompt .= ' Usage context: '.trim($context);
        }

        return $prompt.' Reply with the alt text only.';
    }

    private function textPrompt(string $context): string
    {
        return 'Turn this description of an image into a concise alt text (max 125 characters, plain text, no quotes, no "image of" prefix): '
            .'"'.trim($context).'".'
            .$this->languageInstruction()
            .' Reply with the alt text only.';
    }

    /**
     * Asset alt is not localized in Statamic, so the default site's language is
     * the one deterministic choice that fits every entry referencing the asset.
     */
    private function languageInstruction(): string
    {
        try {
            $locale = (string) Site::default()->locale();
        } catch (\Throwable) {
            $locale = '';
        }

        return $locale !== '' ? " Write it in the site language \"{$locale}\"." : '';
    }

    /** One clean line, unwrapped from quotes, bounded — or null when unusable. */
    private function sanitize(?string $text): ?string
    {
        $t = trim((string) $text);
        $t = trim((string) preg_replace('/\s+/', ' ', $t), " \t\"'“”‘’");

        if ($t === '' || mb_strlen($t) > self::MAX_LENGTH * 2) {
            // Empty or degenerate (the model rambled) — better no alt than junk.
            return null;
        }

        return mb_substr($t, 0, self::MAX_LENGTH);
    }
}

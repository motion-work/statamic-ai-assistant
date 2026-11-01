<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Statamic\Facades\Entry;

/**
 * Source material appended to an entry's prompt: fetched URL text and Figma
 * frames, unless the model fetches pages itself or a multi-entry run already
 * fetched them.
 */
final class GenerationSourceContext
{
    public function __construct(
        private readonly PromptUrlFetcher $promptUrlFetcher,
        private readonly ?FigmaContentFetcher $figma,
    ) {}

    /**
     * Fetched URL text for an update: the multi-entry run's prefetch, nothing
     * when the model fetches pages itself, else fetched here. No Figma.
     *
     * @param  array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths, appended_to_prompts?: bool}|null  $prefetchedUrlAug
     * @return array{0: array<int, string>, 1: string} warnings, appendix
     */
    public function forUpdate(string $prompt, bool $useUrlTool, ?array $prefetchedUrlAug): array
    {
        if ($prefetchedUrlAug !== null) {
            $urlAug = [
                'appendix' => $prefetchedUrlAug['appendix'],
                'warnings' => $prefetchedUrlAug['warnings'],
            ];
            $combinedAppendix = (bool) ($prefetchedUrlAug['appended_to_prompts'] ?? false)
                ? ''
                : (string) $prefetchedUrlAug['appendix'];
        } elseif ($useUrlTool) {
            $urlAug = ['appendix' => '', 'warnings' => []];
            $combinedAppendix = '';
        } else {
            $urlAug = $this->promptUrlFetcher->buildAugmentation($prompt);
            $combinedAppendix = (string) $urlAug['appendix'];
        }

        return [$urlAug['warnings'], $combinedAppendix];
    }

    /**
     * Source material appended to the prompt: fetched URL text (unless the
     * model fetches pages itself, or a multi-entry run already fetched them)
     * and Figma frames.
     *
     * @param  array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths, appended_to_prompts?: bool}|null  $prefetchedUrlAug
     * @return array{0: array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths}, 1: array{appendix: string, warnings: array<int, string>}, 2: string}
     */
    public function forNewEntry(string $prompt, bool $useUrlTool, ?array $prefetchedUrlAug): array
    {
        if ($prefetchedUrlAug !== null) {
            $urlAug = [
                'appendix' => $prefetchedUrlAug['appendix'],
                'warnings' => $prefetchedUrlAug['warnings'],
                'preferred' => $prefetchedUrlAug['preferred'],
            ];
            $appendedToPrompts = (bool) ($prefetchedUrlAug['appended_to_prompts'] ?? false);
            if ($appendedToPrompts) {
                $figmaAug = ['appendix' => '', 'warnings' => []];
                $combinedAppendix = '';
            } else {
                $figmaAug = $this->figma ? $this->figma->buildAugmentation($prompt) : ['appendix' => '', 'warnings' => []];
                $combinedAppendix = $urlAug['appendix'].$figmaAug['appendix'];
            }
        } elseif ($useUrlTool) {
            // Skip server-side URL pre-fetch when the LLM can call fetch_page_content itself.
            // Otherwise the page body is inlined and the model has no reason to drill into detail pages.
            $urlAug = [
                'appendix' => '',
                'warnings' => [],
                'preferred' => new PreferredAssetPaths,
            ];
            $figmaAug = $this->figma ? $this->figma->buildAugmentation($prompt) : ['appendix' => '', 'warnings' => []];
            $combinedAppendix = $figmaAug['appendix'];
        } else {
            $urlAug = $this->promptUrlFetcher->buildAugmentation($prompt);
            $figmaAug = $this->figma ? $this->figma->buildAugmentation($prompt) : ['appendix' => '', 'warnings' => []];
            $combinedAppendix = $urlAug['appendix'].$figmaAug['appendix'];
        }

        return [$urlAug, $figmaAug, $combinedAppendix];
    }
}

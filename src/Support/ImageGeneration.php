<?php

namespace MotionWork\StatamicAiAssistant\Support;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviders;
use MotionWork\StatamicAiAssistant\Services\Ai\GeneratesImages;
use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;

/**
 * The AI agent's image generation: whether it can run, whether the super
 * admins switched it on, and the scope an entry generation runs it in.
 *
 * Off until a super admin switches it on (Who has access → Image generation),
 * unless image_generation.enabled in config forces it either way. Only on the
 * OpenRouter provider with an `image` profile.
 *
 * EntryGeneratorService runs each entry's completion inside during(), handing
 * over the queue the generated images go to and the entry's image container;
 * GenerationToolLoop offers generate_image only inside such a scope, so the
 * generator's API (which the Claude connector shares) needs no new argument.
 */
final class ImageGeneration
{
    /** @var array{sink: PreferredAssetPaths, container: string}|null */
    private static ?array $scope = null;

    /** Whether the provider can generate images at all (OpenRouter + key + image models). */
    public static function available(): bool
    {
        return self::unavailableReason() === null;
    }

    /** Why image generation cannot run on this site, or null when it can. */
    public static function unavailableReason(): ?string
    {
        if (AiProviders::active() !== AiProviders::OPENROUTER || ! AiProviders::hasCredentials()) {
            return __('Image generation needs the OpenRouter provider (set OPENROUTER_API_KEY).');
        }

        $ai = app(AbstractAiService::class);
        if (! $ai instanceof GeneratesImages || ! $ai->supportsImageGeneration()) {
            return __('No image model is configured (OPENROUTER_IMAGE_MODELS).');
        }

        return null;
    }

    /** The config override (true/false), or null when the CP switch decides. */
    public static function configOverride(): ?bool
    {
        $value = config('statamic-ai-assistant.image_generation.enabled');

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** The switch as set: the config override, else the CP setting, else off. */
    public static function switchedOn(): bool
    {
        return self::configOverride() ?? app(AgentAccessStore::class)->imageGeneration() ?? false;
    }

    /** Whether the agent may generate images on this site right now. */
    public static function enabled(): bool
    {
        return self::switchedOn() && self::available();
    }

    /**
     * Run $fn with generate_image offered to the entry's tool loop (when
     * enabled): generated images are added to $sink, saved in $container. An
     * entry without an image field ($container null) gets no scope, since a
     * generated image would have nowhere to go.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function during(PreferredAssetPaths $sink, ?string $container, callable $fn): mixed
    {
        if ($container === null || ! self::enabled()) {
            return $fn();
        }

        $previous = self::$scope;
        self::$scope = ['sink' => $sink, 'container' => $container];

        try {
            return $fn();
        } finally {
            self::$scope = $previous;
        }
    }

    /**
     * The scope of the entry being generated, or null outside one.
     *
     * @return array{sink: PreferredAssetPaths, container: string}|null
     */
    public static function scope(): ?array
    {
        return self::$scope;
    }
}

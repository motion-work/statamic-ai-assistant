<?php

namespace MotionWork\StatamicAiAssistant\Support;

use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviders;

/**
 * The "free models only" mode of the OpenRouter provider: whether it is on,
 * and which privacy policy its requests use.
 *
 * Off until a super admin switches it on (Who has access → Free models), unless
 * openrouter_free_models.enabled in config forces it either way. Only on the
 * OpenRouter provider with a key. The model choice itself is FreeModelPicker's.
 */
final class FreeModels
{
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    /** Whether free mode is switched on and can run. */
    public static function enabled(): bool
    {
        return self::switchedOn() && self::unavailableReason() === null;
    }

    /** The switch as set: the config override, else the CP setting, else off. */
    public static function switchedOn(): bool
    {
        return self::configOverride('enabled') ?? app(AgentAccessStore::class)->freeModels() ?? false;
    }

    /** Whether free mode may use models that train on prompts. */
    public static function trainingAllowed(): bool
    {
        return self::configOverride('allow_training') ?? app(AgentAccessStore::class)->freeModelsTraining() ?? false;
    }

    /** OpenRouter's data_collection value for free-mode requests. */
    public static function dataCollection(): string
    {
        return self::trainingAllowed() ? self::ALLOW : self::DENY;
    }

    /** Why free mode cannot run on this site, or null when it can. */
    public static function unavailableReason(): ?string
    {
        if (AiProviders::active() !== AiProviders::OPENROUTER || ! AiProviders::hasCredentials()) {
            return __('Free models need the OpenRouter provider (set OPENROUTER_API_KEY).');
        }

        return null;
    }

    /**
     * A config override of openrouter_free_models.$key, or null when the CP
     * switch decides.
     */
    public static function configOverride(string $key): ?bool
    {
        $value = config('statamic-ai-assistant.openrouter_free_models.'.$key);

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

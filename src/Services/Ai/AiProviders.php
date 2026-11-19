<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\ClaudeCli\ClaudeCliClient;
use MotionWork\StatamicAiAssistant\Services\Ai\ClaudeCli\ClaudeCliRunner;
use MotionWork\StatamicAiAssistant\Services\ClaudeCliService;
use MotionWork\StatamicAiAssistant\Services\InfomaniakService;
use MotionWork\StatamicAiAssistant\Services\MateoService;
use MotionWork\StatamicAiAssistant\Services\OpenRouterService;

/**
 * Which AI provider the addon talks to.
 *
 * `provider_name` may name one (openrouter, infomaniak, mateo, claude-cli). Left on "auto"
 * (the default), OpenRouter is used once OPENROUTER_API_KEY is set, Infomaniak
 * otherwise, so sites that only have Infomaniak credentials keep working. An
 * unknown name (e.g. the removed "groq") is treated as auto.
 */
final class AiProviders
{
    public const OPENROUTER = 'openrouter';

    public const INFOMANIAK = 'infomaniak';

    /** mateo's AI gateway (sites built by mateo): only when named, never picked by auto. */
    public const MATEO = 'mateo';

    /**
     * The local Claude Code CLI, for development on the CLI's subscription: only
     * when named, never picked by auto, never in production.
     */
    public const CLAUDE_CLI = 'claude-cli';

    /** Config key of the credential each provider needs. */
    private const CREDENTIALS = [
        self::OPENROUTER => 'openrouter_api_key',
        self::INFOMANIAK => 'infomaniak_api_token',
        self::MATEO => 'mateo_gateway_token',
    ];

    public static function active(): string
    {
        $name = strtolower(trim((string) config('statamic-ai-assistant.provider_name', 'auto')));

        if ($name === self::CLAUDE_CLI || array_key_exists($name, self::CREDENTIALS)) {
            return $name;
        }

        return self::credentialSet(self::OPENROUTER) ? self::OPENROUTER : self::INFOMANIAK;
    }

    /** Whether the active provider has its API key or token. */
    public static function hasCredentials(): bool
    {
        if (self::active() === self::CLAUDE_CLI) {
            return ClaudeCliClient::allowed() && ClaudeCliRunner::binary() !== null;
        }

        return self::credentialSet(self::active());
    }

    public static function make(): AbstractAiService
    {
        return match (self::active()) {
            self::OPENROUTER => new OpenRouterService,
            self::MATEO => new MateoService,
            self::CLAUDE_CLI => new ClaudeCliService,
            default => new InfomaniakService,
        };
    }

    private static function credentialSet(string $provider): bool
    {
        return trim((string) config('statamic-ai-assistant.'.self::CREDENTIALS[$provider])) !== '';
    }
}

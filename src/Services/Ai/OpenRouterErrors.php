<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * Turns an OpenRouter error response into a message an editor can act on.
 *
 * OpenRouter answers `{"error": {"code", "message", "metadata"}}`. Credits and
 * budgets are told apart by `metadata.limit_source`; moderation blocks carry
 * `metadata.reasons`. See https://openrouter.ai/docs/api-reference/errors
 */
final class OpenRouterErrors
{
    private const BUDGET_SOURCES = [
        'openrouter_guardrail_budget',
        'openrouter_workspace_budget',
        'openrouter_key_limit',
    ];

    /**
     * @param  bool  $freeModels  Free mode: overload and the daily limit get their own wording.
     */
    public static function exception(Response $response, bool $freeModels = false): AiProviderException
    {
        $status = $response->status();
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $meta = is_array($error['metadata'] ?? null) ? $error['metadata'] : [];
        $limitSource = is_string($meta['limit_source'] ?? null) ? $meta['limit_source'] : null;
        $hint = is_string($error['message'] ?? null) ? $error['message'] : mb_substr($response->body(), 0, 300);

        $message = $freeModels ? self::freeModelsMessage($status, $hint) : null;

        $message ??= match ($status) {
            401 => __('The OpenRouter API key is invalid or disabled. Check OPENROUTER_API_KEY.'),
            402 => self::paymentRequired($limitSource),
            403 => self::forbidden($limitSource, $meta, $hint),
            // 404 covers unknown models and "no endpoint supports this request" (e.g. a tool_choice).
            404 => __('No AI model can serve this request with the configured OpenRouter models and routing rules: :hint', ['hint' => $hint]),
            503 => __('No AI provider can take this request with the current routing rules (for example privacy or tool support). Try again later, or relax the OpenRouter provider settings.'),
            default => __('OpenRouter request failed (:status): :hint', ['status' => $status, 'hint' => $hint]),
        };

        return new AiProviderException($message, $status, $limitSource);
    }

    /**
     * Free mode: the daily free-request limit, busy free models, or no free
     * model allowed by the privacy setting; null for anything else.
     */
    private static function freeModelsMessage(int $status, string $hint): ?string
    {
        if ($status === 429 && preg_match('/free-models-per-day|per.?day/i', $hint) === 1) {
            return __('The daily limit for free models is reached. It continues tomorrow, or switch the free models off.');
        }

        if ($status === 429 || $status === 503) {
            return __('The free models are busy right now. Try again in a moment.');
        }

        if ($status === 404 && str_contains(strtolower($hint), 'data policy')) {
            return __('No free model can take this request with the current privacy setting. Try again later, or allow models that use prompts for training.');
        }

        return null;
    }

    private static function paymentRequired(?string $limitSource): string
    {
        return match ($limitSource) {
            'openrouter_key_limit' => __('The OpenRouter key for this site has reached its spending limit. Raise the limit on openrouter.ai or wait until it resets.'),
            'openrouter_in_flight_budget' => __('Too many AI requests are running at once on this OpenRouter account. Try again in a moment.'),
            default => __('The OpenRouter account is out of credits. Top up credits on openrouter.ai.'),
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function forbidden(?string $limitSource, array $meta, string $hint): string
    {
        if (in_array($limitSource, self::BUDGET_SOURCES, true)) {
            $resetsAt = self::formatReset($meta['resets_at'] ?? null);

            return $resetsAt === null
                ? __('The OpenRouter budget for this site is used up.')
                : __('The OpenRouter budget for this site is used up. It resets on :date.', ['date' => $resetsAt]);
        }

        $type = $meta['error_type'] ?? null;
        if (isset($meta['reasons']) || in_array($type, ['content_policy_violation', 'refusal'], true)) {
            return __('The AI provider declined this request (content moderation).');
        }

        return __('OpenRouter refused the request: :hint', ['hint' => $hint]);
    }

    private static function formatReset(mixed $resetsAt): ?string
    {
        if (! is_string($resetsAt) || trim($resetsAt) === '') {
            return null;
        }

        $timestamp = strtotime($resetsAt);

        // An unexpected format is still worth showing as sent.
        if ($timestamp === false) {
            return $resetsAt;
        }

        return Carbon::createFromTimestamp($timestamp)->timezone((string) config('app.timezone', 'UTC'))->format('d.m.Y H:i');
    }
}

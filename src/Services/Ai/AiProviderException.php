<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A provider refused or failed a request. The message is meant for editors;
 * `status` and `limitSource` let callers tell credit and budget limits apart
 * (OpenRouter's error.metadata.limit_source, e.g. "openrouter_key_limit").
 */
final class AiProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $limitSource = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Out of credits, or a key / workspace / guardrail budget is used up. */
    public function isBudgetLimit(): bool
    {
        return $this->status === 402 || ($this->status === 403 && $this->limitSource !== null);
    }

    /**
     * Uncaught in a CP request (the field buttons let provider failures bubble):
     * answer with the editor-readable message, flagged so the browser shows it
     * instead of its generic sentence. Always 502, never the provider's own
     * 401/403, which the CP would read as the editor's session failing. Any
     * other request falls back to Laravel's default rendering.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'ai_provider_error' => true,
            'budget_limit' => $this->isBudgetLimit(),
        ], 502);
    }
}

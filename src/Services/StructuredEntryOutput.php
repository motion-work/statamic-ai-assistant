<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviderException;

/**
 * Runs an entry-generation call with the entry's JSON Schema as OpenRouter
 * `response_format`, and once without it when the provider rejects the schema.
 *
 * Structured outputs only ever help: a rejected schema (400 invalid schema,
 * 404 / 422 no endpoint for these parameters) is logged and the call repeats
 * without it, and the schema is then left off for the rest of this generation.
 * Other providers, a disabled toggle, or a blueprint with no schema run the
 * call unchanged.
 */
final class StructuredEntryOutput
{
    /** Statuses that mean "this schema / parameter combination cannot be served". */
    private const REJECTION_STATUSES = [400, 404, 422];

    private bool $rejected = false;

    public function __construct(private readonly AbstractAiService $aiService) {}

    public function enabled(): bool
    {
        return $this->aiService instanceof OpenRouterService
            && (bool) config('statamic-ai-assistant.openrouter_structured_outputs', true);
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>|null  $responseFormat  From EntryResponseSchema::responseFormat()
     * @param  callable(): T  $call
     * @return T
     */
    public function run(?array $responseFormat, callable $call): mixed
    {
        if ($responseFormat === null || $this->rejected || ! $this->enabled()) {
            return $call();
        }

        try {
            return $this->aiService->withRequestOptions(['response_format' => $responseFormat], $call);
        } catch (AiProviderException $e) {
            if ($e->isBudgetLimit() || ! in_array($e->status, self::REJECTION_STATUSES, true)) {
                throw $e;
            }

            $this->rejected = true;
            Log::warning('[entry-gen] provider rejected the entry response schema; retrying without structured output', [
                'status' => $e->status,
                'message' => $e->getMessage(),
            ]);

            return $call();
        }
    }
}

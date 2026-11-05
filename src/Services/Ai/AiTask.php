<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

/**
 * What a provider call is for. The task picks the model (an OpenRouter profile,
 * or the strong/fast/vision model of Infomaniak), and whether the model may
 * reason first.
 *
 * Calls made outside AbstractAiService::forTask() are Agent calls: the AI agent's
 * planning and entry generation, which keep reasoning on.
 */
enum AiTask: string
{
    /** AI agent: planning and entry generation, with tools. */
    case Agent = 'agent';

    /** Small helper calls: target selection, collection matching, block hints. */
    case Light = 'light';

    /** Ai Text / Bard field buttons: generate and refactor. */
    case Field = 'field';

    /** LLM translation fallback (no DeepL key). */
    case Translate = 'translate';

    /** Image understanding: alt text, analyze_image. */
    case Vision = 'vision';

    /** Image generation (OpenRouter's images endpoint): the agent's generate_image tool. */
    case Image = 'image';

    /** Embeddings for semantic entry search (OpenRouter's embeddings endpoint). */
    case Embedding = 'embedding';

    /**
     * Quick tasks answer directly: reasoning models skip thinking for them
     * (see AbstractAiService::withoutThinking()).
     */
    public function isQuick(): bool
    {
        return $this !== self::Agent;
    }
}

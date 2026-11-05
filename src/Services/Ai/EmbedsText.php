<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

/**
 * A provider that can turn text into embedding vectors (OpenRouter's
 * embeddings endpoint). Kept apart from AbstractAiService, which every
 * provider implements: whether search uses it is SemanticSearch::status().
 */
interface EmbedsText
{
    /** The `embedding` profile's model, or null when none is configured. */
    public function embeddingModel(): ?string;

    /** Vector length requested from the model. */
    public function embeddingDimensions(): int;

    /**
     * One vector per input, in input order.
     *
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    public function embed(array $inputs): array;
}

<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

/**
 * OpenRouter's embeddings endpoint (POST /embeddings): a list of inputs in,
 * one float vector per input out (`data[].embedding`, matched by `index`).
 * Errors are mapped like chat completions (editor-readable 402/403 etc.).
 */
final class OpenRouterEmbeddings
{
    public function __construct(private readonly OpenAiCompatibleClient $client) {}

    /**
     * @param  list<string>  $inputs
     * @param  array<string, mixed>  $params  dimensions, user, provider
     * @return array{vectors: list<list<float>>, generation_id: ?string, usage: array<string, mixed>}
     */
    public function embed(string $model, array $inputs, array $params): array
    {
        $response = $this->client->send(['model' => $model, 'input' => $inputs, 'encoding_format' => 'float', ...$params]);

        if (! $response->successful()) {
            throw OpenRouterErrors::exception($response);
        }

        $vectors = $this->vectors($response->json('data'), count($inputs));
        $usage = $response->json('usage');
        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            $id = $response->header('X-Generation-Id') ?: null;
        }

        return [
            'vectors' => $vectors,
            'generation_id' => $id,
            'usage' => is_array($usage) ? $usage : [],
        ];
    }

    /**
     * The vectors in input order. Any missing or malformed vector fails the
     * whole batch: a partial result would pair texts with the wrong vectors.
     *
     * @return list<list<float>>
     */
    private function vectors(mixed $data, int $count): array
    {
        $byIndex = [];
        foreach (is_array($data) ? $data : [] as $position => $item) {
            $vector = is_array($item) ? ($item['embedding'] ?? null) : null;
            if (is_array($vector) && $vector !== []) {
                $byIndex[(int) ($item['index'] ?? $position)] = array_map('floatval', $vector);
            }
        }

        if (count($byIndex) !== $count) {
            throw new \RuntimeException(__('The embedding model returned no vectors.'));
        }
        ksort($byIndex);

        return array_values($byIndex);
    }
}

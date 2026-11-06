<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai\Free;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OpenRouter's free models, most capable first.
 *
 * The text models come from GET /models?max_price=0&sort=intelligence-high-to-low,
 * so OpenRouter's own capability ranking is the order; a second request with
 * min_tool_success_rate marks the models whose tool calls are reliable (the
 * model objects carry no success rate). Embedding models come from
 * /embeddings/models, larger ones first. Only `:free` ids count (anonymous
 * zero-priced test models are skipped), minus the configured exclusions.
 *
 * Cached for openrouter_free_models.catalog_ttl; when OpenRouter can't be
 * reached, the last good catalog is used, else the configured fallback list.
 */
final class FreeModelCatalog
{
    private const CACHE_KEY = 'statamic-ai-assistant.free-models.catalog';

    private const STALE_KEY = 'statamic-ai-assistant.free-models.catalog-stale';

    /** Free embedding models below this context can't take an entry's text. */
    private const MIN_EMBEDDING_CONTEXT = 8192;

    /**
     * @return array{text: list<array{id: string, intelligence: ?float, context: int, tools: bool, reliable_tools: ?bool, structured: bool, reasoning_mandatory: bool, image_input: bool}>, embedding: list<string>}
     */
    public function current(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $fresh = $this->fetch();
        if ($fresh !== null) {
            Cache::put(self::CACHE_KEY, $fresh, (int) config('statamic-ai-assistant.openrouter_free_models.catalog_ttl', 21600));
            Cache::forever(self::STALE_KEY, $fresh);

            return $fresh;
        }

        $stale = Cache::get(self::STALE_KEY);

        return is_array($stale) ? $stale : $this->fallback();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::STALE_KEY);
    }

    /** @return array{text: list<array<string, mixed>>, embedding: list<string>}|null */
    private function fetch(): ?array
    {
        $models = $this->get('/models', ['max_price' => 0, 'sort' => 'intelligence-high-to-low']);
        if ($models === null) {
            return null;
        }

        $reliable = $this->get('/models', [
            'max_price' => 0,
            'supported_parameters' => 'tools',
            'min_tool_success_rate' => (float) config('statamic-ai-assistant.openrouter_free_models.agent_min_tool_success_rate', 0.9),
        ]);
        $reliableIds = $reliable === null ? null : array_column($reliable, 'id');

        return [
            'text' => $this->textModels($models, $reliableIds),
            'embedding' => $this->embeddingModels($this->get('/embeddings/models', []) ?? []),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $models  In OpenRouter's capability order.
     * @param  list<string>|null  $reliableIds  Null when the reliability lookup failed.
     * @return list<array<string, mixed>>
     */
    private function textModels(array $models, ?array $reliableIds): array
    {
        $out = [];

        foreach ($models as $model) {
            $id = (string) ($model['id'] ?? '');
            if (! $this->eligible($id) || ! $this->outputsText($model)) {
                continue;
            }

            $params = is_array($model['supported_parameters'] ?? null) ? $model['supported_parameters'] : [];
            $inputs = is_array($model['architecture']['input_modalities'] ?? null) ? $model['architecture']['input_modalities'] : [];
            $index = $model['benchmarks']['artificial_analysis']['intelligence_index'] ?? null;

            $out[] = [
                'id' => $id,
                'intelligence' => is_numeric($index) ? (float) $index : null,
                'context' => (int) ($model['context_length'] ?? 0),
                'tools' => in_array('tools', $params, true),
                'reliable_tools' => $reliableIds === null ? null : in_array($id, $reliableIds, true),
                'structured' => in_array('structured_outputs', $params, true),
                'reasoning_mandatory' => (bool) ($model['reasoning']['mandatory'] ?? false),
                'image_input' => in_array('image', $inputs, true),
            ];
        }

        return $out;
    }

    /**
     * Free embedding ids, the largest model first ("8b" before "1b" before "350m").
     *
     * @param  list<array<string, mixed>>  $models
     * @return list<string>
     */
    private function embeddingModels(array $models): array
    {
        $usable = array_filter($models, fn (array $m) => $this->eligible((string) ($m['id'] ?? ''))
            && (int) ($m['context_length'] ?? 0) >= self::MIN_EMBEDDING_CONTEXT);

        $ids = array_map(fn (array $m) => (string) $m['id'], array_values($usable));
        usort($ids, fn (string $a, string $b) => self::parameterCount($b) <=> self::parameterCount($a));

        return $ids;
    }

    /** A model's size from its id ("8b" → 8e9, "350m" → 3.5e8), 0 when unknown. */
    public static function parameterCount(string $id): float
    {
        if (preg_match('/(\d+(?:\.\d+)?)([bm])(?![a-z])/i', $id, $m) !== 1) {
            return 0.0;
        }

        return (float) $m[1] * (strtolower($m[2]) === 'b' ? 1e9 : 1e6);
    }

    private function eligible(string $id): bool
    {
        if (! str_ends_with($id, ':free')) {
            return false;
        }

        foreach ((array) config('statamic-ai-assistant.openrouter_free_models.exclude', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && Str::is($pattern, $id)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $model */
    private function outputsText(array $model): bool
    {
        $outputs = $model['architecture']['output_modalities'] ?? null;

        return ! is_array($outputs) || in_array('text', $outputs, true);
    }

    /**
     * The configured fallback, used when OpenRouter's catalog can't be read:
     * assumed to handle tools and text, so the agent keeps working.
     *
     * @return array{text: list<array<string, mixed>>, embedding: list<string>}
     */
    private function fallback(): array
    {
        $text = array_map(fn (string $id) => [
            'id' => $id,
            'intelligence' => null,
            'context' => 128000,
            'tools' => true,
            'reliable_tools' => null,
            'structured' => false,
            'reasoning_mandatory' => false,
            'image_input' => false,
        ], $this->configList('fallback_models'));

        return ['text' => $text, 'embedding' => $this->configList('fallback_embedding_models')];
    }

    /** @return list<string> */
    private function configList(string $key): array
    {
        $value = config('statamic-ai-assistant.openrouter_free_models.'.$key, []);
        $items = is_string($value) ? explode(',', $value) : (array) $value;

        return array_values(array_filter(array_map(fn ($s) => trim((string) $s), $items), fn (string $s) => $s !== ''));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>|null
     */
    private function get(string $path, array $query): ?array
    {
        $base = rtrim((string) config('statamic-ai-assistant.openrouter_base_url', 'https://openrouter.ai/api/v1'), '/');

        try {
            $response = Http::timeout(10)
                ->withToken(trim((string) config('statamic-ai-assistant.openrouter_api_key')))
                ->acceptJson()
                ->get($base.$path, $query);
        } catch (\Throwable $e) {
            Log::notice('OpenRouter free model catalog could not be read', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        $data = $response->successful() ? $response->json('data') : null;
        if (! is_array($data)) {
            Log::notice('OpenRouter free model catalog could not be read', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        return array_values(array_filter($data, 'is_array'));
    }
}

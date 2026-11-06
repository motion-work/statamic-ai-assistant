<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai\Free;

use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Support\FreeModels;

/**
 * Picks the most capable free models for a task.
 *
 * Candidates are the catalog's free models (most capable first) that meet the
 * task's needs: the AI agent needs tool calls that work reliably and a large
 * context; image analysis needs image input; every task needs text. Among
 * those, models with the task's preferred trait come first (structured output
 * for the agent, answering without reasoning for quick tasks), then capability.
 * The best two usable ones are sent, with `openrouter/free` (OpenRouter's own
 * free router) last: OpenRouter accepts at most three models per request.
 */
final class FreeModelPicker
{
    public const ROUTER = 'openrouter/free';

    /** OpenRouter's limit for the `models` fallback list. */
    public const MAX_MODELS = 3;

    public function __construct(
        private readonly FreeModelCatalog $catalog,
        private readonly FreeModelHealth $health,
    ) {}

    /**
     * $base (an openrouter_profiles profile) with the free models for $task in
     * place of its models; presets are dropped, reasoning and limits kept.
     *
     * @param  array{models: list<string>, preset: ?string, reasoning: ?string, max_tokens: ?int, temperature: ?float}  $base
     * @return array{models: list<string>, preset: ?string, reasoning: ?string, max_tokens: ?int, temperature: ?float}
     */
    public function profile(AiTask $task, array $base): array
    {
        return ['models' => $this->models($task, FreeModels::dataCollection()), 'preset' => null] + $base;
    }

    /**
     * Model ids for a call of $task: the best usable candidates, the router last.
     * Empty for image generation, which has no free models.
     *
     * @return list<string>
     */
    public function models(AiTask $task, string $policy): array
    {
        if ($task === AiTask::Image) {
            return [];
        }

        if ($task === AiTask::Embedding) {
            $model = $this->embeddingModel($policy);

            return $model === null ? [] : [$model];
        }

        $usable = array_filter($this->candidates($task), fn (array $m) => $this->health->usable($m['id'], $policy));
        $best = array_slice(array_column(array_values($usable), 'id'), 0, self::MAX_MODELS - 1);

        return [...$best, self::ROUTER];
    }

    /**
     * The best free embedding model, or null. Every free embedding model only
     * answers when prompts may be used for training (tested live, Nov 2026),
     * so none is picked without that.
     */
    public function embeddingModel(string $policy): ?string
    {
        if ($policy !== FreeModels::ALLOW) {
            return null;
        }

        foreach ($this->catalog->current()['embedding'] as $id) {
            if ($this->health->usable($id, $policy)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Whether $model can answer without reasoning (effort "none"). Unknown
     * models and the router say no: some of the models it routes to reject it.
     */
    public function canSkipReasoning(?string $model): bool
    {
        foreach ($this->catalog->current()['text'] as $entry) {
            if ($entry['id'] === $model) {
                return ! $entry['reasoning_mandatory'];
            }
        }

        return false;
    }

    /**
     * The first model each task would use now, for the settings page.
     *
     * @return array<string, ?string>
     */
    public function firstChoices(string $policy): array
    {
        $choices = [];
        foreach ([AiTask::Agent, AiTask::Light, AiTask::Field, AiTask::Translate, AiTask::Vision, AiTask::Embedding] as $task) {
            $choices[$task->value] = $this->models($task, $policy)[0] ?? null;
        }

        return $choices;
    }

    /**
     * The catalog's models that fit $task, preferred trait first, then capability.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(AiTask $task): array
    {
        $fitting = [];
        foreach ($this->catalog->current()['text'] as $rank => $entry) {
            if ($this->fits($entry, $task)) {
                $fitting[] = ['rank' => $rank] + $entry;
            }
        }

        usort($fitting, fn (array $a, array $b) => [$this->lacksPreferred($a, $task), $a['rank']] <=> [$this->lacksPreferred($b, $task), $b['rank']]);

        return array_map(fn (array $m) => array_diff_key($m, ['rank' => true]), $fitting);
    }

    /** @param  array<string, mixed>  $entry */
    private function fits(array $entry, AiTask $task): bool
    {
        return match ($task) {
            AiTask::Agent => $entry['tools']
                && $entry['reliable_tools'] !== false
                && $entry['context'] >= (int) config('statamic-ai-assistant.openrouter_free_models.agent_min_context', 64000),
            AiTask::Vision => $entry['image_input'],
            default => true,
        };
    }

    /**
     * 1 when the model lacks the task's preferred trait, so it sorts after
     * those that have it.
     *
     * @param  array<string, mixed>  $entry
     */
    private function lacksPreferred(array $entry, AiTask $task): int
    {
        $has = $task === AiTask::Agent ? $entry['structured'] : ! $entry['reasoning_mandatory'];

        return $has ? 0 : 1;
    }
}

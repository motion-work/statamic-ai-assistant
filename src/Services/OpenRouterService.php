<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\Ai\EmbedsText;
use MotionWork\StatamicAiAssistant\Services\Ai\GeneratedImage;
use MotionWork\StatamicAiAssistant\Services\Ai\GeneratesImages;
use MotionWork\StatamicAiAssistant\Services\Ai\OpenAiCompatibleClient;
use MotionWork\StatamicAiAssistant\Services\Ai\OpenAiCompatibleService;
use MotionWork\StatamicAiAssistant\Services\Ai\OpenRouterEmbeddings;
use MotionWork\StatamicAiAssistant\Services\Ai\OpenRouterErrors;
use MotionWork\StatamicAiAssistant\Services\Ai\OpenRouterImages;
use Statamic\Facades\User;

/**
 * OpenRouter: one API in front of every model. Each AiTask has a profile in
 * config (openrouter_profiles): its models in fallback order, an optional
 * preset, the reasoning effort, and optional max_tokens / temperature. Provider
 * preferences (openrouter_provider) decide which upstream providers may serve
 * the request, e.g. only those that do not collect data.
 *
 * Every request names the site (HTTP-Referer, X-OpenRouter-Title) and the
 * signed-in editor as a hashed `user`, so OpenRouter can attribute usage and
 * keep one editor's moderation flag from affecting the whole account.
 */
class OpenRouterService extends OpenAiCompatibleService implements EmbedsText, GeneratesImages
{
    private const PROVIDER_SORTS = ['price', 'throughput', 'latency'];

    protected function providerName(): string
    {
        return 'openrouter';
    }

    protected function providerLabel(): string
    {
        return 'OpenRouter';
    }

    protected function client(): OpenAiCompatibleClient
    {
        return $this->apiClient('/chat/completions');
    }

    /** A client for one OpenRouter endpoint (chat completions, images). */
    private function apiClient(string $endpoint): OpenAiCompatibleClient
    {
        $apiKey = trim((string) config('statamic-ai-assistant.openrouter_api_key'));

        if ($apiKey === '') {
            throw new \RuntimeException(__('OPENROUTER_API_KEY is not configured.'));
        }

        $baseUrl = rtrim((string) config('statamic-ai-assistant.openrouter_base_url', 'https://openrouter.ai/api/v1'), '/');
        $title = trim((string) config('statamic-ai-assistant.openrouter_app_title')) ?: (string) config('app.name');

        return new OpenAiCompatibleClient(
            $baseUrl.$endpoint,
            $apiKey,
            max(30, (int) config('statamic-ai-assistant.openrouter_http_timeout', 180)),
            array_filter([
                'HTTP-Referer' => (string) config('app.url'),
                'X-OpenRouter-Title' => $title,
            ], fn (string $value) => $value !== ''),
        );
    }

    public function supportsVision(): bool
    {
        $profile = $this->profile(AiTask::Vision);

        return $profile['models'] !== [] || $profile['preset'] !== null;
    }

    public function supportsImageGeneration(): bool
    {
        return $this->profile(AiTask::Image)['models'] !== [];
    }

    /**
     * One image from the `image` profile's models, tried in order (see
     * OpenRouterImages). Reported as an AiTask::Image completion, so the usage
     * log records its cost.
     *
     * @param  array{aspect_ratio?: string}  $options
     */
    public function generateImage(string $prompt, array $options = []): GeneratedImage
    {
        $models = $this->profile(AiTask::Image)['models'];

        if ($models === []) {
            throw new \RuntimeException(__('No OpenRouter model is configured for the :task task.', ['task' => AiTask::Image->value]));
        }

        return $this->forTask(AiTask::Image, function () use ($models, $prompt, $options): GeneratedImage {
            $started = hrtime(true);
            $result = (new OpenRouterImages($this->apiClient('/images')))->generate($models, $prompt, $this->imageParams($options));

            $this->finished(['models' => $models], [
                'id' => $result['generation_id'],
                'model' => $result['image']->model,
                'usage' => $result['usage'],
            ], false, $started);

            return $result['image'];
        });
    }

    public function embeddingModel(): ?string
    {
        return $this->profile(AiTask::Embedding)['models'][0] ?? null;
    }

    public function embeddingDimensions(): int
    {
        return max(64, (int) config('statamic-ai-assistant.openrouter_profiles.embedding.dimensions', 512));
    }

    /**
     * Vectors from the `embedding` profile's model, reported as an
     * AiTask::Embedding completion so the usage log records the cost.
     *
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    public function embed(array $inputs): array
    {
        $model = $this->embeddingModel();

        if ($model === null) {
            throw new \RuntimeException(__('No OpenRouter model is configured for the :task task.', ['task' => AiTask::Embedding->value]));
        }

        return $this->forTask(AiTask::Embedding, function () use ($model, $inputs): array {
            $started = hrtime(true);
            $params = array_filter([
                'dimensions' => $this->embeddingDimensions(),
                'user' => (string) ($this->requestOptions()['user'] ?? $this->userHash() ?? ''),
                'provider' => $this->providerPreferences(),
            ], fn ($value) => $value !== '' && $value !== []);
            $result = (new OpenRouterEmbeddings($this->apiClient('/embeddings')))->embed($model, $inputs, $params);

            $this->finished(['model' => $model], [
                'id' => $result['generation_id'],
                // The endpoint answers with the bare name (no author prefix),
                // which would read as a fallback: one model is ever requested.
                'model' => $model,
                'usage' => $result['usage'],
            ], false, $started);

            return $result['vectors'];
        });
    }

    /**
     * Request fields for the images endpoint, from the image_generation config.
     * Its `provider` object only knows only / ignore / sort, so the privacy
     * fields of chat completions are not sent.
     *
     * @param  array{aspect_ratio?: string}  $options
     * @return array<string, mixed>
     */
    private function imageParams(array $options): array
    {
        $config = (array) config('statamic-ai-assistant.image_generation', []);

        $params = array_filter([
            'aspect_ratio' => (string) ($options['aspect_ratio'] ?? $config['aspect_ratio'] ?? '16:9'),
            'resolution' => (string) ($config['resolution'] ?? '1K'),
            'output_format' => (string) ($config['output_format'] ?? 'jpeg'),
            'user' => (string) ($this->requestOptions()['user'] ?? $this->userHash() ?? ''),
        ], fn (string $value) => $value !== '');

        $provider = array_intersect_key($this->providerPreferences(), array_flip(['only', 'ignore', 'sort']));
        if ($provider !== []) {
            $params['provider'] = $provider;
        }

        return $params;
    }

    protected function modelFields(AiTask $task): array
    {
        $profile = $this->profile($task);
        $models = $profile['models'];
        $preset = $profile['preset'];

        if ($models === [] && $preset === null) {
            throw new \RuntimeException(__('No OpenRouter model is configured for the :task task.', ['task' => $task->value]));
        }

        $fields = $models === [] ? ['model' => $preset] : ['model' => $models[0]];

        if (count($models) > 1) {
            $fields['models'] = $models;
        }

        if ($models !== [] && $preset !== null) {
            $fields['preset'] = $preset;
        }

        $reasoning = $this->reasoning($profile['reasoning']);
        if ($reasoning !== null) {
            $fields['reasoning'] = $reasoning;
        }

        return $fields;
    }

    /**
     * withoutThinking() wins over the profile: quick tasks answer directly. The
     * models of quick-task profiles must therefore accept effort "none" (models
     * with mandatory reasoning reject it).
     *
     * @return array{effort: string}|null
     */
    private function reasoning(?string $effort): ?array
    {
        if ($this->thinkingDisabled()) {
            return ['effort' => 'none'];
        }

        return $effort === null ? null : ['effort' => $effort];
    }

    protected function routingFields(array $payload): array
    {
        $fields = [];

        $provider = $this->providerPreferences();
        if (isset($payload['response_format'])) {
            // Never route structured output to an upstream that ignores the schema.
            // Not sent for tools: tested live (Nov 2026), it filters out every
            // Anthropic endpoint for a forced tool_choice and for temperature
            // alongside reasoning, while the same requests succeed without it.
            $provider['require_parameters'] = true;
        }
        if (is_array($payload['provider'] ?? null)) {
            $provider = array_replace($provider, $payload['provider']);
        }
        if ($provider !== []) {
            $fields['provider'] = $provider;
        }

        $user = $this->userHash();
        if (! isset($payload['user']) && $user !== null) {
            $fields['user'] = $user;
        }

        return array_replace($fields, $this->cachingFields($payload));
    }

    /**
     * Structured output (`response_format`) is sent with require_parameters, and
     * the OpenAI and Anthropic reasoning endpoints do not list `temperature`:
     * tested live (Nov 2026), every one of them is filtered out ("Filter by
     * Parameters", 404) while the same request without temperature routes. They
     * ignore temperature anyway, so it is left off such requests.
     *
     * {@inheritdoc}
     */
    protected function payload(array $messages, bool $stream = false, ?int $maxTokens = null, array $tools = [], string|array $toolChoice = 'auto'): array
    {
        $payload = parent::payload($messages, $stream, $maxTokens, $tools, $toolChoice);

        if (isset($payload['response_format'])) {
            unset($payload['temperature']);
        }

        return $payload;
    }

    /**
     * Prompt caching (openrouter_prompt_caching): the agent's session id as
     * `session_id`, so OpenRouter keeps the planner rounds and every entry of an
     * agent session on the same provider, where the shared prompt prefix is
     * cached; and, for AI agent calls, a top-level `cache_control` breakpoint,
     * which Anthropic needs before it caches anything (OpenAI, Google and others
     * cache implicitly and ignore it). Quick tasks send no breakpoint: their
     * prompts are short and one-off, and an Anthropic cache write costs 1.25x.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cachingFields(array $payload): array
    {
        if (! config('statamic-ai-assistant.openrouter_prompt_caching', true)) {
            return [];
        }

        $fields = [];

        $sessionId = $this->completionContext()['agent_session_id'] ?? null;
        if (! isset($payload['session_id']) && is_string($sessionId) && $sessionId !== '') {
            $fields['session_id'] = mb_substr($sessionId, 0, 256);
        }

        if ($this->currentTask() === AiTask::Agent && ! isset($payload['cache_control'])) {
            $fields['cache_control'] = ['type' => 'ephemeral'];
        }

        return $fields;
    }

    /** OpenRouter accepts any request option (session_id, plugins, provider, …). */
    protected function allowedRequestOptions(): ?array
    {
        return null;
    }

    protected function taskMaxTokens(AiTask $task): ?int
    {
        return $this->profile($task)['max_tokens'];
    }

    protected function taskTemperature(AiTask $task): ?float
    {
        return $this->profile($task)['temperature'];
    }

    protected function failureException(Response $response): \RuntimeException
    {
        return OpenRouterErrors::exception($response);
    }

    protected function emptyReplyMessage(): string
    {
        return __('The AI returned no text. Check the OpenRouter models in the AI assistant config and the prompt size.');
    }

    protected function completionFinished(AiCompletionFinished $event): void
    {
        $context = [
            'task' => $event->task->value,
            'model' => $event->model,
            'requested' => $event->requestedModels,
            'generation_id' => $event->generationId,
            'cost' => $event->cost(),
        ];

        // A fallback model answering is worth noticing; routine calls are not.
        if ($event->usedFallback()) {
            Log::info('OpenRouter answered with a fallback model', $context);

            return;
        }

        Log::debug('OpenRouter completion', $context);
    }

    /**
     * The profile of $task, normalised. Models may be a list or a
     * comma-separated string.
     *
     * @return array{models: list<string>, preset: ?string, reasoning: ?string, max_tokens: ?int, temperature: ?float}
     */
    private function profile(AiTask $task): array
    {
        $raw = config('statamic-ai-assistant.openrouter_profiles.'.$task->value);
        $raw = is_array($raw) ? $raw : [];

        $preset = trim((string) ($raw['preset'] ?? ''));
        if ($preset !== '' && ! str_starts_with($preset, '@preset/')) {
            $preset = '@preset/'.$preset;
        }

        $reasoning = trim((string) ($raw['reasoning'] ?? ''));

        return [
            'models' => $this->stringList($raw['models'] ?? []),
            'preset' => $preset !== '' ? $preset : null,
            'reasoning' => $reasoning !== '' ? strtolower($reasoning) : null,
            'max_tokens' => is_numeric($raw['max_tokens'] ?? null) ? (int) $raw['max_tokens'] : null,
            'temperature' => is_numeric($raw['temperature'] ?? null) ? (float) $raw['temperature'] : null,
        ];
    }

    /**
     * The `provider` routing object from config, without unset fields.
     *
     * @return array<string, mixed>
     */
    private function providerPreferences(): array
    {
        $config = config('statamic-ai-assistant.openrouter_provider');
        $config = is_array($config) ? $config : [];
        $preferences = [];

        $dataCollection = strtolower(trim((string) ($config['data_collection'] ?? '')));
        if (in_array($dataCollection, ['allow', 'deny'], true)) {
            $preferences['data_collection'] = $dataCollection;
        }

        if (($config['zdr'] ?? false) === true) {
            $preferences['zdr'] = true;
        }

        foreach (['only', 'ignore'] as $key) {
            $slugs = $this->stringList($config[$key] ?? []);
            if ($slugs !== []) {
                $preferences[$key] = $slugs;
            }
        }

        $sort = strtolower(trim((string) ($config['sort'] ?? '')));
        if (in_array($sort, self::PROVIDER_SORTS, true)) {
            $preferences['sort'] = $sort;
        }

        return $preferences;
    }

    /**
     * A list of non-empty strings from a list or a comma-separated string.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $items = is_string($value) ? explode(',', $value) : (array) $value;

        return array_values(array_filter(array_map(fn ($s) => trim((string) $s), $items), fn (string $s) => $s !== ''));
    }

    /**
     * A stable, non-reversible id for the signed-in CP user (never the raw id
     * or email). Null outside a CP request, e.g. in queued agent jobs, where a
     * caller can pass `user` through withRequestOptions().
     */
    private function userHash(): ?string
    {
        $user = User::current();

        if ($user === null) {
            return null;
        }

        return self::userHashFor((string) $user->id());
    }

    /**
     * The `user` value OpenRouter receives for a CP user id: HMAC-keyed with
     * APP_KEY and truncated, so it is stable per site but not reversible.
     * Public for queued jobs, which have no signed-in user to hash.
     */
    public static function userHashFor(string $userId): string
    {
        return substr(hash_hmac('sha256', $userId, (string) config('app.key')), 0, 32);
    }
}

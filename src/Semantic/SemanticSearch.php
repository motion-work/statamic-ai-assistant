<?php

namespace MotionWork\StatamicAiAssistant\Semantic;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviders;
use MotionWork\StatamicAiAssistant\Services\Ai\EmbedsText;
use MotionWork\StatamicAiAssistant\Support\FreeModels;
use Statamic\Facades\Site;

/**
 * Whether semantic search can run, and the nearest entries for a query.
 *
 * Callers fall back to the keyword search whenever nearest() returns null:
 * feature off, not OpenRouter or no key, no embedding model, no index for the
 * site (or one built with another model, or too large), or any failure while
 * embedding the query or reading the index. A failure is logged once per
 * process and never thrown, so neither the agent, the editor nor the Claude
 * connector ever sees one.
 */
class SemanticSearch
{
    public const DISABLED = 'disabled';

    public const PROVIDER = 'provider';

    public const NO_MODEL = 'no_model';

    /** Free mode without training allowed: every free embedding model needs it. */
    public const FREE_TRAINING = 'free_training';

    public const NOT_BUILT = 'not_built';

    public const OUTDATED = 'outdated';

    public const TOO_MANY = 'too_many';

    public const UNREADABLE = 'unreadable';

    /** How long a query's vector is reused (seconds). */
    private const QUERY_CACHE_SECONDS = 600;

    /** @var array<string, true> */
    private static array $warned = [];

    public function __construct(private readonly AbstractAiService $ai) {}

    /** Why entries cannot be embedded at all, or null when they can. */
    public function providerReason(): ?string
    {
        if (! (bool) config('statamic-ai-assistant.semantic_search.enabled', true)) {
            return self::DISABLED;
        }
        if (AiProviders::active() !== AiProviders::OPENROUTER || ! AiProviders::hasCredentials() || ! $this->ai instanceof EmbedsText) {
            return self::PROVIDER;
        }

        if (FreeModels::enabled() && ! FreeModels::trainingAllowed()) {
            return self::FREE_TRAINING;
        }

        return $this->ai->embeddingModel() === null ? self::NO_MODEL : null;
    }

    /** Why a search on $site falls back to keywords, or null when it does not. */
    public function reason(string $site): ?string
    {
        $providerReason = $this->providerReason();
        if ($providerReason !== null) {
            return $providerReason;
        }

        try {
            return $this->indexReason(new EmbeddingIndex($site));
        } catch (\Throwable $e) {
            self::warnOnce('index', $e);

            return self::UNREADABLE;
        }
    }

    /**
     * The nearest entries of $site, or null to fall back to keyword search.
     *
     * @return list<array{id: string, score: float}>|null
     */
    public function nearest(string $site, string $query, ?string $collection, int $limit): ?array
    {
        if (trim($query) === '' || $this->reason($site) !== null) {
            return null;
        }

        try {
            return (new EmbeddingIndex($site))->nearest(
                $this->queryVector($query),
                $collection,
                $limit,
                (float) config('statamic-ai-assistant.semantic_search.min_similarity', 0.3),
            );
        } catch (\Throwable $e) {
            self::warnOnce('search', $e);

            return null;
        }
    }

    /** The site whose entries the agent searches (the CP's selected site). */
    public static function currentSite(): string
    {
        return Site::selected()?->handle() ?? Site::default()->handle();
    }

    /** Log a semantic-search failure once per process (keyword search took over). */
    public static function warnOnce(string $what, \Throwable $e): void
    {
        if (isset(self::$warned[$what])) {
            return;
        }
        self::$warned[$what] = true;

        Log::warning('Semantic search fell back to keyword search ('.$what.')', ['message' => $e->getMessage()]);
    }

    /** Forget which failures were logged (tests, long-running workers). */
    public static function forgetWarnings(): void
    {
        self::$warned = [];
    }

    private function indexReason(EmbeddingIndex $index): ?string
    {
        $manifest = $index->manifest();

        if ($manifest === null || $manifest['entries'] === []) {
            return self::NOT_BUILT;
        }
        if (! $index->matches((string) $this->ai->embeddingModel(), $this->ai->embeddingDimensions())) {
            return self::OUTDATED;
        }

        return count($manifest['entries']) > (int) config('statamic-ai-assistant.semantic_search.max_entries', 5000)
            ? self::TOO_MANY
            : null;
    }

    /** @return list<float> */
    private function queryVector(string $query): array
    {
        $key = 'statamic-ai-assistant:semantic-query:'.sha1($this->ai->embeddingModel().'|'.$this->ai->embeddingDimensions().'|'.mb_strtolower(trim($query)));

        return Cache::remember($key, self::QUERY_CACHE_SECONDS, fn () => $this->ai->embed([trim($query)])[0]);
    }
}

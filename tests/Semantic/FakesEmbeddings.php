<?php

namespace MotionWork\StatamicAiAssistant\Tests\Semantic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndexer;
use MotionWork\StatamicAiAssistant\Semantic\SemanticSearch;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;

/**
 * OpenRouter with a fake embeddings endpoint: each input becomes a vector on
 * one "topic" axis by the words it contains, so similarity is predictable
 * (care ↔ wood furniture, warranty ↔ repairs, payment ↔ invoice).
 */
trait FakesEmbeddings
{
    protected const DIMENSIONS = 64;

    /** @var list<array<string, mixed>> */
    protected array $embeddingRequests = [];

    protected string $indexPath = '';

    protected function useSemanticSearch(): void
    {
        $this->indexPath = sys_get_temp_dir().'/ai-assistant-embeddings-'.bin2hex(random_bytes(4));
        Cache::flush();
        SemanticSearch::forgetWarnings();

        config([
            'statamic-ai-assistant.provider_name' => 'openrouter',
            'statamic-ai-assistant.openrouter_api_key' => 'sk-or-test',
            'statamic-ai-assistant.ai_http_retry_times' => 1,
            'statamic-ai-assistant.openrouter_profiles.embedding' => ['models' => 'openai/text-embedding-3-small', 'dimensions' => self::DIMENSIONS],
            'statamic-ai-assistant.semantic_search' => ['enabled' => true, 'path' => $this->indexPath, 'queue' => null, 'max_entries' => 5000, 'min_similarity' => 0.3],
        ]);
        $this->app->forgetInstance(AbstractAiService::class);
        $this->app->forgetInstance(SemanticSearch::class);
        $this->app->forgetInstance(EmbeddingIndexer::class);

        $this->fakeEmbeddings();
    }

    /** Status the fake endpoint answers with (Http::fake keeps its first stub per URL). */
    protected int $embeddingStatus = 200;

    private bool $embeddingsFaked = false;

    protected function fakeEmbeddings(int $status = 200): void
    {
        $this->embeddingRequests = [];
        $this->embeddingStatus = $status;
        Cache::flush();

        if ($this->embeddingsFaked) {
            return;
        }
        $this->embeddingsFaked = true;

        Http::fake(['*/embeddings' => function (Request $request) {
            $this->embeddingRequests[] = $request->data();
            $status = $this->embeddingStatus;
            if ($status !== 200) {
                return Http::response(['error' => ['code' => $status, 'message' => 'Embedding failed']], $status);
            }
            $data = [];
            foreach ((array) $request->data()['input'] as $i => $text) {
                $data[] = ['object' => 'embedding', 'index' => $i, 'embedding' => self::topicVector((string) $text)];
            }

            return Http::response(['id' => 'gen-emb-1', 'object' => 'list', 'data' => $data, 'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10, 'cost' => 0.0000002]]);
        }]);
    }

    protected function tearDownSemanticSearch(): void
    {
        if ($this->indexPath !== '') {
            File::deleteDirectory($this->indexPath);
        }
    }

    /** @return list<float> */
    protected static function topicVector(string $text): array
    {
        $text = mb_strtolower($text);
        $topics = [
            0 => ['pflege', 'holz', 'möbel', 'öl'],
            1 => ['garantie', 'reparatur', 'rückgabe', 'defekt'],
            2 => ['zahlung', 'bezahl', 'rechnung', 'twint'],
        ];

        $vector = array_fill(0, self::DIMENSIONS, 0.0);
        foreach ($topics as $axis => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    $vector[$axis] += 1.0;
                }
            }
        }
        $vector[self::DIMENSIONS - 1] = 0.05;

        return $vector;
    }
}

<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services\Free;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A faked OpenRouter for free mode: the catalog endpoints answer with a fixed
 * free-model list (most capable first), chat completions with $chatReply.
 */
trait FakesFreeOpenRouter
{
    /** @var list<Request> */
    protected array $openRouterRequests = [];

    /** @var array<string, mixed> */
    protected array $chatReply = [];

    protected int $chatStatus = 200;

    protected bool $catalogDown = false;

    protected function setUpFreeOpenRouter(): void
    {
        Cache::flush();
        config([
            'statamic-ai-assistant.provider_name' => 'openrouter',
            'statamic-ai-assistant.openrouter_api_key' => 'sk-or-test',
            'statamic-ai-assistant.openrouter_base_url' => 'https://openrouter.ai/api/v1',
            'statamic-ai-assistant.openrouter_free_models.enabled' => true,
            'statamic-ai-assistant.openrouter_free_models.allow_training' => false,
            'statamic-ai-assistant.ai_http_retry_times' => 1,
        ]);

        $this->chatReply = [
            'id' => 'gen-free',
            'model' => 'nvidia/super-vl:free',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Antwort'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7, 'cost' => 0],
        ];

        Http::fake(function (Request $request) {
            $this->openRouterRequests[] = $request;
            $url = $request->url();

            if (str_contains($url, '/embeddings/models')) {
                return $this->catalogDown ? Http::response([], 500) : Http::response(['data' => self::embeddingCatalog()]);
            }
            if (str_contains($url, '/models')) {
                if ($this->catalogDown) {
                    return Http::response([], 500);
                }

                return Http::response(['data' => str_contains($url, 'min_tool_success_rate') ? self::reliableCatalog() : self::textCatalog()]);
            }

            return Http::response($this->chatReply, $this->chatStatus);
        });
    }

    /** @return list<array<string, mixed>> In OpenRouter's capability order. */
    protected static function textCatalog(): array
    {
        return [
            self::model('stealth/alpha', ['tools', 'structured_outputs'], ['text', 'image'], 1000000),
            self::model('nvidia/super-vl:free', ['tools', 'structured_outputs', 'reasoning'], ['text', 'image'], 1000000, false, 42.0),
            self::model('nvidia/ultra:free', ['tools', 'reasoning'], ['text'], 1000000, true, 40.0),
            self::model('google/gemma:free', ['tools'], ['text', 'image'], 262144, false),
            self::model('nvidia/content-safety:free', ['reasoning'], ['text', 'image'], 128000),
            self::model('acme/small:free', ['tools'], ['text'], 32000, true),
            self::model('cohere/mini:free', ['tools', 'structured_outputs'], ['text'], 256000, false),
            self::model('openai/gpt-paid', ['tools'], ['text'], 400000),
            self::model('openrouter/free', ['tools', 'structured_outputs'], ['text', 'image'], 200000, true),
            ['id' => 'google/lyria:free', 'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['audio']], 'context_length' => 100000],
        ];
    }

    /** @return list<array<string, mixed>> The free models with reliable tool calls. */
    protected static function reliableCatalog(): array
    {
        return [
            ['id' => 'nvidia/super-vl:free'],
            ['id' => 'nvidia/ultra:free'],
            ['id' => 'cohere/mini:free'],
            ['id' => 'acme/small:free'],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected static function embeddingCatalog(): array
    {
        return [
            ['id' => 'nvidia/embed-1b:free', 'context_length' => 32768],
            ['id' => 'liquid/embed-350m:free', 'context_length' => 512],
            ['id' => 'nvidia/embed-8b:free', 'context_length' => 32768],
            ['id' => 'openai/text-embedding-3-small', 'context_length' => 8192],
        ];
    }

    /**
     * @param  list<string>  $params
     * @param  list<string>  $inputs
     * @return array<string, mixed>
     */
    private static function model(string $id, array $params, array $inputs, int $context, bool $mandatory = false, ?float $intelligence = null): array
    {
        return [
            'id' => $id,
            'context_length' => $context,
            'supported_parameters' => $params,
            'architecture' => ['input_modalities' => $inputs, 'output_modalities' => ['text']],
            'reasoning' => ['mandatory' => $mandatory],
            'benchmarks' => ['artificial_analysis' => ['intelligence_index' => $intelligence]],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function chatPayloads(): array
    {
        return array_values(array_map(
            fn (Request $r) => $r->data(),
            array_filter($this->openRouterRequests, fn (Request $r) => str_contains($r->url(), '/chat/completions')),
        ));
    }
}

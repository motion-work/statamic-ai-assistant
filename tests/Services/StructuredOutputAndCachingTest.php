<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviderException;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\EditorialGuidanceService;
use MotionWork\StatamicAiAssistant\Services\EntryCompletion;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationPlanner;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Services\EntryJsonParser;
use MotionWork\StatamicAiAssistant\Services\EntryResponseSchema;
use MotionWork\StatamicAiAssistant\Services\EntrySearch;
use MotionWork\StatamicAiAssistant\Services\EntryStructureSerializer;
use MotionWork\StatamicAiAssistant\Services\FieldOptionCatalog;
use MotionWork\StatamicAiAssistant\Services\FieldSchemaBuilder;
use MotionWork\StatamicAiAssistant\Services\GenerationPrompt;
use MotionWork\StatamicAiAssistant\Services\GenerationToolLoop;
use MotionWork\StatamicAiAssistant\Services\InfomaniakService;
use MotionWork\StatamicAiAssistant\Services\OpenRouterService;
use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Services\PromptUrlFetcher;
use MotionWork\StatamicAiAssistant\Services\SetHintsService;
use MotionWork\StatamicAiAssistant\Services\StructuredEntryOutput;
use MotionWork\StatamicAiAssistant\Support\PlanEntryDecorator;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;

/**
 * Phase 4a: the entry JSON Schema sent as OpenRouter structured output (and
 * dropped once when a provider rejects it), and prompt caching (session_id
 * from the agent session, cache_control on agent calls, a stable prompt prefix).
 */
class StructuredOutputAndCachingTest extends TestCase
{
    /** @var array<int, Request> */
    private array $requests = [];

    /** @var array<int, array{0: array<string, mixed>, 1: int}> */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic-ai-assistant.openrouter_api_key' => 'sk-or-test',
            'statamic-ai-assistant.openrouter_profiles' => [
                'agent' => ['models' => 'strong/a, strong/b', 'reasoning' => 'medium'],
                'light' => ['models' => 'cheap/a', 'reasoning' => 'none'],
            ],
            'statamic-ai-assistant.openrouter_provider' => ['data_collection' => 'deny'],
            'statamic-ai-assistant.openrouter_structured_outputs' => true,
            'statamic-ai-assistant.openrouter_prompt_caching' => true,
            'statamic-ai-assistant.ai_http_retry_times' => 1,
            'statamic-ai-assistant.temperature' => 0.5,
            'statamic-ai-assistant.infomaniak_api_token' => 'ik-test',
            'statamic-ai-assistant.infomaniak_product_id' => '123',
            'statamic-ai-assistant.infomaniak_model' => 'mistral24b',
        ]);

        Http::fake(function (Request $request) {
            $this->requests[] = $request;
            [$body, $status] = array_shift($this->replies) ?? [$this->textReply('{"title":"Hallo"}'), 200];

            return Http::response($body, $status);
        });
    }

    /** @return array<string, mixed> */
    private function textReply(string $content): array
    {
        return [
            'id' => 'gen-1',
            'model' => 'strong/a',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.001],
        ];
    }

    /** @param  array<string, mixed>  $body */
    private function queueReply(array $body, int $status = 200): void
    {
        $this->replies[] = [$body, $status];
    }

    private function rejection(int $status): array
    {
        return ['error' => ['code' => $status, 'message' => 'No endpoints found that can handle the requested parameters.']];
    }

    /** @return array<string, mixed> */
    private function payload(int $index): array
    {
        $this->assertArrayHasKey($index, $this->requests, "expected request #{$index}");

        return $this->requests[$index]->data();
    }

    /** @return array<string, array<string, mixed>> */
    private function blogFieldSchema(): array
    {
        $blueprint = Blueprint::makeFromFields([
            'title' => ['type' => 'text', 'validate' => ['required']],
            'category' => ['type' => 'select', 'options' => ['news' => 'News', 'event' => 'Event']],
            'status' => ['type' => 'button_group', 'options' => ['draft' => 'Draft', 'live' => 'Live'], 'validate' => ['required']],
            'tags' => ['type' => 'checkboxes', 'options' => ['wood' => 'Wood', 'metal' => 'Metal']],
            'rating' => ['type' => 'integer'],
            'featured' => ['type' => 'toggle'],
            'seo' => ['type' => 'group', 'fields' => [['handle' => 'meta_title', 'field' => ['type' => 'text']]]],
            'facts' => ['type' => 'grid', 'fields' => [['handle' => 'label', 'field' => ['type' => 'text']]]],
            'blocks' => ['type' => 'replicator', 'sets' => ['main' => ['display' => 'Main', 'sets' => [
                'text_block' => ['display' => 'Text', 'fields' => [['handle' => 'body', 'field' => ['type' => 'bard']]]],
                'quote' => ['display' => 'Quote', 'fields' => [
                    ['handle' => 'text', 'field' => ['type' => 'text']],
                    ['handle' => 'author', 'field' => ['type' => 'text']],
                ]],
            ]]]],
        ]);

        return (new FieldSchemaBuilder(new SetHintsService, new FieldOptionCatalog))->buildFieldSchema($blueprint, 'default');
    }

    /** @return array<string, mixed> */
    private function decoded(?array $schema): array
    {
        $this->assertNotNull($schema);

        return json_decode((string) json_encode($schema), true);
    }

    public function test_schema_mirrors_the_blueprint_with_enums_sets_groups_and_rows(): void
    {
        $format = (new EntryResponseSchema)->responseFormat($this->blogFieldSchema());

        $this->assertSame('json_schema', $format['type']);
        $this->assertSame(EntryResponseSchema::NAME, $format['json_schema']['name']);
        $this->assertFalse($format['json_schema']['strict']);

        $schema = $this->decoded($format['json_schema']['schema']);
        $props = $schema['properties'];

        // Create mode: every top-level field is required (Gemini skips optional arrays otherwise).
        $this->assertEqualsCanonicalizing(array_keys($props), $schema['required']);

        $this->assertSame('string', $props['title']['type']);
        // Optional choice also accepts "", a required one only its options.
        $this->assertSame(['news', 'event', ''], $props['category']['enum']);
        $this->assertSame(['draft', 'live'], $props['status']['enum']);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['wood', 'metal']]], $props['tags']);
        $this->assertSame(['number', 'string'], $props['rating']['type']);
        $this->assertSame('boolean', $props['featured']['type']);
        $this->assertSame('string', $props['seo']['properties']['meta_title']['type']);
        $this->assertSame('string', $props['facts']['items']['properties']['label']['type']);

        // Every set is a full branch: Anthropic drops properties a schema does not name.
        $branches = $props['blocks']['items']['anyOf'];
        $this->assertCount(2, $branches);
        $quote = collect($branches)->firstWhere('properties.type.const', 'quote');
        $this->assertSame(['type', 'text', 'author'], array_keys($quote['properties']));
        $this->assertSame(['type'], $quote['required']);
        $text = collect($branches)->firstWhere('properties.type.const', 'text_block');
        $this->assertSame('string', $text['properties']['body']['type']);

        // Every property names a type (Anthropic rejects one without).
        $this->assertNoTypelessProperty($schema);
    }

    /** @param  array<string, mixed>  $node */
    private function assertNoTypelessProperty(array $node): void
    {
        foreach ($node['properties'] ?? [] as $handle => $property) {
            $this->assertTrue(isset($property['type']) || isset($property['anyOf']), "property {$handle} has no type");
            $this->assertNoTypelessProperty($property);
        }
        foreach (['items'] as $key) {
            if (is_array($node[$key] ?? null)) {
                $this->assertNoTypelessProperty($node[$key]);
            }
        }
        foreach ($node['anyOf'] ?? [] as $branch) {
            $this->assertNoTypelessProperty($branch);
        }
    }

    public function test_update_schema_requires_nothing_so_unchanged_fields_can_be_omitted(): void
    {
        $schema = $this->decoded((new EntryResponseSchema)->schema($this->blogFieldSchema(), update: true));

        $this->assertArrayNotHasKey('required', $schema);
        $this->assertSame(['news', 'event', ''], $schema['properties']['category']['enum']);
    }

    public function test_too_deeply_nested_blueprints_get_no_schema_rather_than_a_lossy_one(): void
    {
        $field = ['type' => 'text', 'label' => 'Leaf'];
        for ($i = 0; $i < 9; $i++) {
            $field = ['type' => 'group', 'label' => "Level {$i}", 'fields' => ['inner' => $field]];
        }

        $this->assertNull((new EntryResponseSchema)->responseFormat(['outer' => $field]));
        $this->assertNull((new EntryResponseSchema)->responseFormat([]));
    }

    public function test_structured_output_requests_require_parameters_and_drop_temperature(): void
    {
        $service = new OpenRouterService;
        $format = (new EntryResponseSchema)->responseFormat($this->blogFieldSchema());

        $service->withRequestOptions(['response_format' => $format], fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Entry.']]));
        $service->generateFromMessages([['role' => 'user', 'content' => 'Plain.']]);

        $structured = $this->payload(0);
        $this->assertSame($format, $structured['response_format']);
        $this->assertTrue($structured['provider']['require_parameters']);
        $this->assertArrayNotHasKey('temperature', $structured, 'reasoning endpoints that list no temperature would be filtered out');

        $plain = $this->payload(1);
        $this->assertArrayNotHasKey('response_format', $plain);
        $this->assertArrayNotHasKey('require_parameters', $plain['provider']);
        $this->assertSame(0.5, $plain['temperature']);
    }

    public function test_agent_calls_carry_the_session_id_and_a_cache_breakpoint(): void
    {
        $service = new OpenRouterService;

        $service->withCompletionContext(['agent_session_id' => 'sess-42'], function () use ($service) {
            $service->generateFromMessages([['role' => 'user', 'content' => 'Agent.']]);
            $service->forTask(AiTask::Light, fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Quick.']]));
        });
        $service->generateFromMessages([['role' => 'user', 'content' => 'No session.']]);

        $agent = $this->payload(0);
        $this->assertSame('sess-42', $agent['session_id']);
        $this->assertSame(['type' => 'ephemeral'], $agent['cache_control']);

        $quick = $this->payload(1);
        $this->assertSame('sess-42', $quick['session_id'], 'sticky routing for every call of the session');
        $this->assertArrayNotHasKey('cache_control', $quick, 'short one-off prompts would only pay the cache write');

        $this->assertArrayNotHasKey('session_id', $this->payload(2));
    }

    public function test_caching_toggle_off_sends_neither_session_id_nor_cache_control(): void
    {
        config(['statamic-ai-assistant.openrouter_prompt_caching' => false]);
        $service = new OpenRouterService;

        $service->withCompletionContext(['agent_session_id' => 'sess-42'], fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Agent.']]));

        $this->assertArrayNotHasKey('session_id', $this->payload(0));
        $this->assertArrayNotHasKey('cache_control', $this->payload(0));
    }

    public function test_a_rejected_schema_is_retried_once_without_it_and_then_left_off(): void
    {
        $service = new OpenRouterService;
        $structured = new StructuredEntryOutput($service);
        $format = (new EntryResponseSchema)->responseFormat($this->blogFieldSchema());
        $this->queueReply($this->rejection(404), 404);

        $call = fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Entry.']]);
        $this->assertSame('{"title":"Hallo"}', $structured->run($format, $call));
        $structured->run($format, $call);

        $this->assertCount(3, $this->requests);
        $this->assertArrayHasKey('response_format', $this->payload(0));
        $this->assertArrayNotHasKey('response_format', $this->payload(1));
        $this->assertArrayNotHasKey('response_format', $this->payload(2), 'not tried again within this generation');
    }

    public function test_budget_and_server_errors_are_not_mistaken_for_a_rejected_schema(): void
    {
        $service = new OpenRouterService;
        $format = (new EntryResponseSchema)->responseFormat($this->blogFieldSchema());
        $this->queueReply(['error' => ['code' => 402, 'message' => 'Insufficient credits']], 402);

        try {
            (new StructuredEntryOutput($service))->run($format, fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Entry.']]));
            $this->fail('expected the budget error');
        } catch (AiProviderException $e) {
            $this->assertSame(402, $e->status);
        }

        $this->assertCount(1, $this->requests, 'a budget error is not retried without the schema');
    }

    public function test_structured_output_is_openrouter_only_and_follows_the_toggle(): void
    {
        $format = (new EntryResponseSchema)->responseFormat($this->blogFieldSchema());

        $infomaniak = new InfomaniakService;
        (new StructuredEntryOutput($infomaniak))->run($format, fn () => $infomaniak->generateFromMessages([['role' => 'user', 'content' => 'Entry.']]));
        $this->assertArrayNotHasKey('response_format', $this->payload(0));

        config(['statamic-ai-assistant.openrouter_structured_outputs' => false]);
        $openrouter = new OpenRouterService;
        (new StructuredEntryOutput($openrouter))->run($format, fn () => $openrouter->generateFromMessages([['role' => 'user', 'content' => 'Entry.']]));
        $this->assertArrayNotHasKey('response_format', $this->payload(1));
    }

    public function test_entry_completion_sends_the_schema_and_falls_back_when_it_is_rejected(): void
    {
        config(['statamic-ai-assistant.entry_generator_fetch_url_tool' => false]);
        $service = new OpenRouterService;
        $completion = new EntryCompletion(
            $service,
            new GenerationToolLoop($service, new PromptUrlFetcher, null, new EntryStructureSerializer, new EntrySearch),
            new EntryJsonParser($service),
        );
        $format = (new EntryResponseSchema)->responseFormat($this->blogFieldSchema());
        $messages = [['role' => 'system', 'content' => 'Schema …'], ['role' => 'user', 'content' => 'Write it.']];
        $warnings = [];

        $this->queueReply($this->textReply('{"title":"Mit Schema"}'));
        $data = $completion->entryJson($messages, null, $warnings, new PreferredAssetPaths, null, false, $format);
        $this->assertSame(['title' => 'Mit Schema'], $data);
        $this->assertSame($format, $this->payload(0)['response_format']);

        $this->queueReply($this->rejection(400), 400);
        $this->queueReply($this->textReply('{"title":"Ohne Schema"}'));
        $data = $completion->entryJson($messages, null, $warnings, new PreferredAssetPaths, null, false, $format);
        $this->assertSame(['title' => 'Ohne Schema'], $data);
        $this->assertArrayHasKey('response_format', $this->payload(1));
        $this->assertArrayNotHasKey('response_format', $this->payload(2));

        // The next entry tries the schema again.
        $completion->entryJson($messages, null, $warnings, new PreferredAssetPaths, null, false, $format);
        $this->assertArrayHasKey('response_format', $this->payload(3));
    }

    public function test_planner_rounds_carry_the_agent_session_id(): void
    {
        Cache::flush();
        $batch = new EntryGenerationBatchService;
        $generator = $this->createStub(EntryGeneratorService::class);
        $generator->method('getCollectionsCatalog')->willReturn([
            ['handle' => 'pages', 'title' => 'Pages', 'blueprints' => [['handle' => 'page', 'title' => 'Page']]],
        ]);
        $service = new OpenRouterService;
        $planner = new EntryGenerationPlanner($service, $generator, new PromptUrlFetcher, $batch, new PlanEntryDecorator($generator));
        $sid = $batch->initPlanningSession('default', null, 'Wie viele Seiten gibt es?');

        $this->queueReply([
            'id' => 'gen-p',
            'model' => 'strong/a',
            'choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                'id' => 'call_a',
                'type' => 'function',
                'function' => ['name' => 'answer_question', 'arguments' => json_encode(['answer' => 'Eine.'])],
            ]]], 'finish_reason' => 'tool_calls']],
        ]);

        // PlanEntriesJob runs the planner inside this context (UsageAttribution).
        $service->withCompletionContext(['agent_session_id' => $sid], fn () => $planner->planAgentic($sid));

        $payload = $this->payload(0);
        $this->assertSame($sid, $payload['session_id']);
        $this->assertSame(['type' => 'ephemeral'], $payload['cache_control']);
        $this->assertArrayNotHasKey('response_format', $payload, 'the planner answers with tool calls, not entry JSON');
    }

    public function test_generation_prompts_keep_the_static_part_first(): void
    {
        $prompt = new GenerationPrompt(app(EditorialGuidanceService::class), new SetHintsService);
        $schema = $this->blogFieldSchema();

        $a = $prompt->buildUpdateSystemMessage($schema, 'de', '{"title":"Alt"}');
        $b = $prompt->buildUpdateSystemMessage($schema, 'de', '{"title":"Ganz anders","blocks":[]}');
        $marker = 'Current values for this entry';

        // The entry snapshot is the only per-entry part, and it comes last.
        $this->assertSame(strstr($a, $marker, true), strstr($b, $marker, true));
        $this->assertGreaterThan(0.9, strpos($a, $marker) / strlen($a));

        // The create system message holds no request text and no date.
        $system = $prompt->buildSystemMessage($schema, 'de');
        $this->assertSame($system, $prompt->buildSystemMessage($schema, 'de'));
        $this->assertStringNotContainsString(date('Y-m-d'), $system);
        $user = $prompt->buildUserMessage('Schreibe über Eichentische.', null, "\n\nQuelle: …");
        $this->assertStringNotContainsString('Eichentische', $system);
        $this->assertStringStartsWith('Schreibe über Eichentische.', $user);
    }
}

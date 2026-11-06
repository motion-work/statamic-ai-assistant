<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use Illuminate\Support\Facades\Cache;
use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationPlanner;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Services\PromptUrlFetcher;
use MotionWork\StatamicAiAssistant\Services\RemoteImageFetcher;
use MotionWork\StatamicAiAssistant\Support\PlanEntryDecorator;
use MotionWork\StatamicAiAssistant\Support\WebSearch;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\ChatToolRunner;
use MotionWork\StatamicAiAssistant\Tools\SaveImageTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use MotionWork\StatamicAiAssistant\Tools\UrlFetchTool;
use MotionWork\StatamicAiAssistant\Usage\UsageRecord;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * The agent's per-message web search: offered only where OpenRouter can run
 * it, sent as a server tool the addon never dispatches, its sources kept with
 * the reply, and never a way around the image provenance rule.
 */
class WebSearchTest extends TestCase
{
    private const CITATION = ['type' => 'url_citation', 'url_citation' => [
        'url' => 'https://www.zpk.org/en/information?utm_source=openai',
        'title' => 'Tickets & Opening hours',
        'start_index' => 10,
        'end_index' => 40,
    ]];

    /** @var array<int, array<int, array<string, mixed>>> tools sent per completion */
    private array $sentTools = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'statamic-ai-assistant.provider_name' => 'openrouter',
            'statamic-ai-assistant.openrouter_api_key' => 'sk-or-test',
            'statamic-ai-assistant.openrouter_base_url' => 'https://openrouter.ai/api/v1',
            'statamic-ai-assistant.web_search.enabled' => true,
        ]);
    }

    public function test_it_is_offered_only_on_openrouter_outside_regional_hosts(): void
    {
        $this->assertTrue(WebSearch::available());

        config(['statamic-ai-assistant.openrouter_base_url' => 'https://eu.openrouter.ai/api/v1']);
        $this->assertFalse(WebSearch::available(), 'the EU host has no search engine');

        config(['statamic-ai-assistant.openrouter_base_url' => 'https://openrouter.ai/api/v1']);
        config(['statamic-ai-assistant.provider_name' => 'infomaniak', 'statamic-ai-assistant.infomaniak_api_token' => 'token']);
        $this->assertFalse(WebSearch::available(), 'Infomaniak cannot run it');

        config(['statamic-ai-assistant.provider_name' => 'openrouter', 'statamic-ai-assistant.web_search.enabled' => false]);
        $this->assertFalse(WebSearch::available(), 'switched off in config');

        config(['statamic-ai-assistant.web_search.enabled' => true, 'statamic-ai-assistant.openrouter_free_models.enabled' => true]);
        $this->assertFalse(WebSearch::available(), 'free mode: every search is billed');

        $this->assertFalse(WebSearch::enabledForSession(['web_search' => true]));
    }

    public function test_the_server_tool_carries_the_configured_limits_and_domains(): void
    {
        config([
            'statamic-ai-assistant.web_search.max_results' => 3,
            'statamic-ai-assistant.web_search.allowed_domains' => 'admin.ch, BFS.admin.ch',
            'statamic-ai-assistant.web_search.excluded_domains' => '',
        ]);

        $tool = WebSearch::serverTool();

        $this->assertSame('openrouter:web_search', $tool['type']);
        $this->assertSame(3, $tool['parameters']['max_results']);
        $this->assertSame(['admin.ch', 'bfs.admin.ch'], $tool['parameters']['allowed_domains']);
        $this->assertArrayNotHasKey('excluded_domains', $tool['parameters']);
        $this->assertTrue(WebSearch::isServerTool($tool));
    }

    public function test_citations_are_read_from_annotations_and_deduplicated(): void
    {
        $message = ['annotations' => [
            self::CITATION,
            ['type' => 'url_citation', 'url_citation' => ['url' => 'https://www.zpk.org/en/information/', 'title' => '']],
            ['type' => 'file', 'file' => ['name' => 'x.pdf']],
            ['type' => 'url_citation', 'url_citation' => ['url' => 'javascript:alert(1)', 'title' => 'bad']],
        ]];

        $this->assertSame(
            [['url' => 'https://www.zpk.org/en/information?utm_source=openai', 'title' => 'Tickets & Opening hours']],
            WebSearch::citationsFrom($message),
        );
        $this->assertSame([], WebSearch::citationsFrom(['content' => 'no sources']));
    }

    public function test_inline_citation_markers_are_removed_from_replies_and_tool_arguments(): void
    {
        $marker = "\u{E200}cite\u{E202}turn690447search0\u{E201}";

        $clean = WebSearch::withoutCitationMarkers([
            'content' => "Offen Di–So. {$marker}\nMontag geschlossen.",
            'tool_calls' => [['id' => 'a', 'type' => 'function', 'function' => [
                'name' => 'answer_question',
                'arguments' => json_encode(['answer' => "Di–So 10–17 Uhr {$marker}."]),
            ]]],
        ]);

        $this->assertSame("Offen Di–So.\nMontag geschlossen.", $clean['content']);
        $this->assertSame(['answer' => 'Di–So 10–17 Uhr.'], json_decode($clean['tool_calls'][0]['function']['arguments'], true));
    }

    public function test_the_planner_sends_the_server_tool_only_when_the_editor_switched_it_on(): void
    {
        $batch = new EntryGenerationBatchService;

        $on = $batch->initPlanningSession('default', null, 'Wann hat das Zentrum Paul Klee offen?');
        $batch->setWebSearch($on, true);
        $this->planner($batch, ['role' => 'assistant', 'content' => 'Di–So 10–17 Uhr.', 'annotations' => [self::CITATION]])->planAgentic($on);

        $this->assertContains(WebSearch::serverTool(), $this->sentTools[0]);

        $this->sentTools = [];
        $off = $batch->initPlanningSession('default', null, 'Wann hat das Zentrum Paul Klee offen?');
        $this->planner($batch, ['role' => 'assistant', 'content' => 'Unbekannt.'])->planAgentic($off);

        $this->assertNotContains(WebSearch::serverTool(), $this->sentTools[0]);
    }

    public function test_the_reply_keeps_the_sources_it_cited(): void
    {
        $batch = new EntryGenerationBatchService;
        $sid = $batch->initPlanningSession('default', null, 'Wann hat das Zentrum Paul Klee offen?');
        $batch->setWebSearch($sid, true);

        $this->planner($batch, ['role' => 'assistant', 'content' => 'Di–So 10–17 Uhr.', 'annotations' => [self::CITATION]])->planAgentic($sid);

        $reply = collect($batch->snapshotForProgress($sid)['transcript'])->last();
        $this->assertSame('assistant', $reply['role']);
        $this->assertSame('https://www.zpk.org/en/information?utm_source=openai', $reply['citations'][0]['url']);
    }

    public function test_sources_an_entry_worker_finds_after_the_reply_join_that_reply(): void
    {
        $batch = new EntryGenerationBatchService;
        $sid = $batch->initPlanningSession('default', null, 'Schreibe eine FAQ');

        $batch->addTurnCitations($sid, [['url' => 'https://a.example/1', 'title' => 'A']]);
        $batch->appendAssistantTurn($sid, 'Created one entry.');
        $batch->addTurnCitations($sid, [['url' => 'https://b.example/2', 'title' => 'B']]);

        $reply = collect($batch->snapshotForProgress($sid)['transcript'])->last();
        $this->assertSame(['https://a.example/1', 'https://b.example/2'], array_column($reply['citations'], 'url'));

        $this->assertTrue($batch->reopenForFollowUp($sid, 'Und auf Englisch?', null));
        $batch->appendAssistantTurn($sid, 'Done.');
        $this->assertArrayNotHasKey('citations', collect($batch->snapshotForProgress($sid)['transcript'])->last());
    }

    public function test_entry_generation_uses_web_search_only_inside_an_enabled_scope(): void
    {
        Blueprint::make('package')->setNamespace('collections.packages')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ]]]]]])->save();
        Collection::make('packages')->title('Packages')->save();

        $ai = $this->createStub(AbstractAiService::class);
        $ai->method('supportsChatTools')->willReturn(true);
        $ai->method('cleanResult')->willReturnArgument(0);
        $ai->method('createChatCompletion')->willReturnCallback(function (array $messages, ?int $maxTokens, array $tools) {
            $this->sentTools[] = $tools;

            return ['choices' => [['message' => [
                'role' => 'assistant',
                'content' => '{"title":"Tee"}',
                'annotations' => [self::CITATION],
            ], 'finish_reason' => 'stop']]];
        });
        $this->app->instance(AbstractAiService::class, $ai);
        $this->app->forgetInstance(EntryGeneratorService::class);

        $reported = [];
        WebSearch::during(
            true,
            fn () => app(EntryGeneratorService::class)->generateContent('packages', 'package', 'Schreibe über Tee', 'default'),
            function (array $citations) use (&$reported) {
                $reported = array_merge($reported, $citations);
            },
        );

        $this->assertContains(WebSearch::serverTool(), $this->sentTools[0]);
        $this->assertSame('https://www.zpk.org/en/information?utm_source=openai', $reported[0]['url']);
        $this->assertFalse(WebSearch::activeInScope(), 'the scope ends with the call');

        $this->sentTools = [];
        app(EntryGeneratorService::class)->generateContent('packages', 'package', 'Schreibe über Tee', 'default');
        $this->assertNotContains(WebSearch::serverTool(), $this->sentTools[0]);
    }

    public function test_the_server_tool_is_never_dispatched_locally(): void
    {
        $runner = new ChatToolRunner([], new ToolContext);
        $working = [];

        $runner->consume([['id' => 'ws1', 'type' => 'openrouter:web_search', 'function' => ['name' => 'web_search', 'arguments' => '{}']]], $working);

        $this->assertSame([], $working, 'no local tool reply for a server tool');
        $this->assertSame(0, $runner->totalCalls());
    }

    public function test_a_cited_page_may_be_read_but_never_makes_its_images_saveable(): void
    {
        $page = 'https://news.example/story';
        $image = 'https://news.example/hero.jpg';

        $fetcher = $this->createStub(PromptUrlFetcher::class);
        $fetcher->method('executeChatTool')->willReturn(json_encode(['ok' => true, 'url' => $page, 'content' => "Story text {$image}"]));

        $context = new ToolContext;
        $context->rememberCitations([['url' => $page.'?utm_source=openai', 'title' => 'Story']]);

        $fetched = (new UrlFetchTool($fetcher, ['client.example']))->handle(json_encode(['url' => $page]), $context);
        $this->assertTrue($fetched['ok'], 'the cited page is fetched');

        $refused = (new UrlFetchTool($fetcher, ['client.example']))->handle(json_encode(['url' => 'https://other.example/page']), $context);
        $this->assertSame('url_not_allowed', $refused['error'], 'an uncited, unprovided page stays closed');

        $save = (new SaveImageTool($this->createStub(RemoteImageFetcher::class), ['client.example']))
            ->handle(json_encode(['url' => $image]), $context);
        $this->assertSame('image_url_not_allowed', $save['error']);
    }

    public function test_usage_records_the_searches_of_a_call(): void
    {
        $event = new AiCompletionFinished('openrouter', AiTask::Agent, ['openai/gpt-6-luna'], 'openai/gpt-6-luna', 'gen-1', [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'cost' => 0.011,
            'server_tool_use_details' => ['web_search_requests' => 2],
        ], false, 900);

        $this->assertSame(2, UsageRecord::fromEvent($event, null)['web_searches']);

        $plain = new AiCompletionFinished('infomaniak', AiTask::Agent, ['m'], 'm', null, ['prompt_tokens' => 1], false, 10);
        $this->assertSame(0, UsageRecord::fromEvent($plain, null)['web_searches']);
    }

    /**
     * A planner whose model answers with $reply (plain text ends the run).
     *
     * @param  array<string, mixed>  $reply
     */
    private function planner(EntryGenerationBatchService $batch, array $reply): EntryGenerationPlanner
    {
        $ai = $this->createStub(AbstractAiService::class);
        $ai->method('supportsChatTools')->willReturn(true);
        $ai->method('createChatCompletion')->willReturnCallback(function (array $messages, ?int $maxTokens, array $tools) use ($reply) {
            $this->sentTools[] = $tools;

            return ['choices' => [['message' => $reply, 'finish_reason' => 'stop']]];
        });

        $generator = $this->createStub(EntryGeneratorService::class);
        $generator->method('getCollectionsCatalog')->willReturn([
            ['handle' => 'pages', 'title' => 'Pages', 'blueprints' => [['handle' => 'page', 'title' => 'Page']]],
        ]);

        return new EntryGenerationPlanner($ai, $generator, new PromptUrlFetcher, $batch, new PlanEntryDecorator($generator));
    }
}

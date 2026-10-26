<?php

namespace MotionWork\StatamicAiAssistant\Tests\Jobs;

use MotionWork\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use MotionWork\StatamicAiAssistant\Jobs\PlanEntriesJob;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationPlanner;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Services\PromptUrlFetcher;
use MotionWork\StatamicAiAssistant\Support\PlanEntryDecorator;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class PlanEntriesJobTest extends TestCase
{
    private EntryGenerationBatchService $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->batch = new EntryGenerationBatchService;
    }

    public function test_non_auto_resolve_session_dispatches_a_single_generator_job(): void
    {
        Bus::fake();

        $generator = $this->createMock(EntryGeneratorService::class);
        // Non-auto session already has collection+blueprint chosen — planner must NOT
        // touch the catalog or the LLM.
        $generator->expects($this->once())->method('getCollectionsCatalog')
            ->willReturn([
                ['handle' => 'pages', 'title' => 'Pages', 'blueprints' => [
                    ['handle' => 'page', 'title' => 'Page'],
                ]],
            ]);

        $decorator = new PlanEntryDecorator($generator);
        $aiService = $this->createMock(AbstractAiService::class);
        $aiService->expects($this->never())->method('createChatCompletion');

        $planner = new EntryGenerationPlanner(
            $aiService,
            $generator,
            new PromptUrlFetcher,
            null,
            $this->batch,
            $decorator,
        );

        $sid = $this->batch->initPlanningSession(
            'default',
            null,
            'Write the contact page.',
            false,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
            'pages',
            'page',
        );

        (new PlanEntriesJob($sid))->handle($this->batch, $planner, $generator, $decorator);

        $session = $this->batch->getSession($sid);
        $this->assertSame('planned', $session['planning_status']);
        $this->assertCount(1, $session['entry_order']);

        $entryId = $session['entry_order'][0];
        Bus::assertDispatched(GeneratePlannedEntryJob::class, function (GeneratePlannedEntryJob $job) use ($sid, $entryId) {
            return $job->sessionId === $sid && $job->entryId === $entryId;
        });
        Bus::assertDispatchedTimes(GeneratePlannedEntryJob::class, 1);
    }

    public function test_auto_resolve_runs_agentic_planner_and_dispatches_per_create_entry_job_call(): void
    {
        Bus::fake();

        $catalog = [
            ['handle' => 'pages', 'title' => 'Pages', 'blueprints' => [
                ['handle' => 'page', 'title' => 'Page'],
            ]],
            ['handle' => 'news', 'title' => 'News', 'blueprints' => [
                ['handle' => 'article', 'title' => 'Article'],
            ]],
        ];

        $generator = $this->createStub(EntryGeneratorService::class);
        $generator->method('getCollectionsCatalog')->willReturn($catalog);

        $decorator = new PlanEntryDecorator($generator);

        // The agentic planner: round 1 → two parallel create_entry_job calls,
        // round 2 → final assistant text "Done — 2 entries dispatched.".
        $aiService = $this->createMock(AbstractAiService::class);
        $aiService->method('supportsChatTools')->willReturn(true);

        $aiService->expects($this->exactly(2))
            ->method('createChatCompletion')
            ->willReturnOnConsecutiveCalls(
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [
                                [
                                    'id' => 'call_a',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'create_entry_job',
                                        'arguments' => json_encode([
                                            'collection' => 'news',
                                            'blueprint' => 'article',
                                            'label' => 'Story A',
                                            'prompt' => 'Write story A from https://example.com/a',
                                        ]),
                                    ],
                                ],
                                [
                                    'id' => 'call_b',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'create_entry_job',
                                        'arguments' => json_encode([
                                            'collection' => 'news',
                                            'blueprint' => 'article',
                                            'label' => 'Story B',
                                            'prompt' => 'Write story B from https://example.com/b',
                                        ]),
                                    ],
                                ],
                            ],
                        ],
                    ]],
                ],
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Done — 2 entries dispatched.',
                        ],
                    ]],
                ],
            );

        $planner = new EntryGenerationPlanner(
            $aiService,
            $generator,
            new PromptUrlFetcher,
            null,
            $this->batch,
            $decorator,
        );

        $sid = $this->batch->initPlanningSession(
            'default',
            null,
            'Create one entry per article on the news listing.',
            true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        (new PlanEntriesJob($sid))->handle($this->batch, $planner, $generator, $decorator);

        $session = $this->batch->getSession($sid);
        $this->assertSame('planned', $session['planning_status']);
        $this->assertNull($session['planner_error']);
        $this->assertCount(2, $session['entry_order']);

        $rows = array_values($session['entries']);
        $this->assertSame('news', $rows[0]['collection']);
        $this->assertSame('article', $rows[0]['blueprint']);
        $this->assertSame('Story A', $rows[0]['label']);
        $this->assertSame('News', $rows[0]['collection_title']);
        $this->assertSame('Article', $rows[0]['blueprint_title']);
        $this->assertSame('Story B', $rows[1]['label']);

        Bus::assertDispatchedTimes(GeneratePlannedEntryJob::class, 2);
        $dispatchedIds = [];
        Bus::assertDispatched(GeneratePlannedEntryJob::class, function (GeneratePlannedEntryJob $job) use ($sid, &$dispatchedIds) {
            if ($job->sessionId !== $sid) {
                return false;
            }
            $dispatchedIds[] = $job->entryId;

            return true;
        });
        sort($dispatchedIds);
        $orderIds = $session['entry_order'];
        sort($orderIds);
        $this->assertSame($orderIds, $dispatchedIds);
    }

    public function test_agentic_planner_self_corrects_when_it_bails_without_searching(): void
    {
        Bus::fake();

        // A real target entry the model claims (wrongly) it cannot identify.
        Blueprint::make('package')->setNamespace('collections.packages')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ]]]]],
        ])->save();
        Collection::make('packages')->title('Packages')->sites(['default'])->save();
        Entry::make()->id('salt-stone-id')->collection('packages')->locale('default')
            ->slug('salt-stone')->data(['title' => 'Salt & Stone'])->save();

        $catalog = [
            ['handle' => 'packages', 'title' => 'Packages', 'blueprints' => [
                ['handle' => 'package', 'title' => 'Package'],
            ]],
        ];

        $generator = $this->createStub(EntryGeneratorService::class);
        $generator->method('getCollectionsCatalog')->willReturn($catalog);

        $decorator = new PlanEntryDecorator($generator);

        $aiService = $this->createMock(AbstractAiService::class);
        $aiService->method('supportsChatTools')->willReturn(true);

        // Round 1: the model gives up WITHOUT calling any tool (the observed bug).
        // Round 2: after the self-correction nudge, it does the update.
        // Round 3: it finishes.
        $aiService->expects($this->exactly(3))
            ->method('createChatCompletion')
            ->willReturnOnConsecutiveCalls(
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Cannot proceed: the user provided only the title "Salt & Stone". '
                                .'I cannot determine which entry they mean or whether it exists.',
                        ],
                    ]],
                ],
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_u',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'update_entry_job',
                                    'arguments' => json_encode([
                                        'entry_id' => 'salt-stone-id',
                                        'label' => 'Salt & Stone',
                                        'prompt' => 'Change the title to "Bodies and Soule".',
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                ],
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Done — 1 action dispatched.',
                        ],
                    ]],
                ],
            );

        $planner = new EntryGenerationPlanner(
            $aiService,
            $generator,
            new PromptUrlFetcher,
            null,
            $this->batch,
            $decorator,
        );

        $sid = $this->batch->initPlanningSession(
            'default',
            null,
            'update "Salt & Stone" title to salts and stones',
            true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        (new PlanEntriesJob($sid))->handle($this->batch, $planner, $generator, $decorator);

        $session = $this->batch->getSession($sid);

        // The first-round give-up was recovered from, not surfaced as a failure.
        $this->assertSame('planned', $session['planning_status']);
        $this->assertNull($session['planner_error']);
        $this->assertCount(1, $session['entry_order']);

        $row = array_values($session['entries'])[0];
        $this->assertSame('salt-stone-id', $row['entry_id']);
        Bus::assertDispatchedTimes(GeneratePlannedEntryJob::class, 1);
    }

    public function test_agentic_planner_answers_a_read_only_question_as_success(): void
    {
        Bus::fake();

        $generator = $this->createStub(EntryGeneratorService::class);
        $generator->method('getCollectionsCatalog')->willReturn([
            ['handle' => 'packages', 'title' => 'Packages', 'blueprints' => [['handle' => 'package', 'title' => 'Package']]],
        ]);
        $generator->method('searchEntryContent')->willReturn([
            ['id' => 'caee315a', 'title' => 'Yoga & Wellness with Nora Vega Marti', 'slug' => 'yoga', 'collection' => 'packages', 'snippet' => '…Kursleitung: Nora Vega Marti…'],
        ]);

        $decorator = new PlanEntryDecorator($generator);

        $aiService = $this->createMock(AbstractAiService::class);
        $aiService->method('supportsChatTools')->willReturn(true);

        // Round 1: search the content. Round 2: answer_question (terminal, success).
        $aiService->expects($this->exactly(2))
            ->method('createChatCompletion')
            ->willReturnOnConsecutiveCalls(
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_s',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'search_entry_content',
                                    'arguments' => json_encode(['query' => 'Kursleitung: Nora Vega Marti']),
                                ],
                            ]],
                        ],
                    ]],
                ],
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_a',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'answer_question',
                                    'arguments' => json_encode([
                                        'answer' => 'The entry "Yoga & Wellness with Nora Vega Marti" (id caee315a) contains that phrase.',
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                ],
            );

        $planner = new EntryGenerationPlanner(
            $aiService,
            $generator,
            new PromptUrlFetcher,
            null,
            $this->batch,
            $decorator,
        );

        $sid = $this->batch->initPlanningSession(
            'default',
            null,
            'which entry contains: Kursleitung: Nora Vega Marti ?',
            true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        (new PlanEntriesJob($sid))->handle($this->batch, $planner, $generator, $decorator);

        $session = $this->batch->getSession($sid);

        // A question answered is a SUCCESS, not "Something went wrong".
        $this->assertSame('planned', $session['planning_status']);
        $this->assertNull($session['planner_error']);
        $this->assertStringContainsString('caee315a', $session['planner_answer']);
        $this->assertCount(0, $session['entry_order']);
        Bus::assertNotDispatched(GeneratePlannedEntryJob::class);

        // And it surfaces on the progress snapshot the CP polls.
        $snap = $this->batch->snapshotForProgress($sid);
        $this->assertNull($snap['planner_error']);
        $this->assertStringContainsString('caee315a', $snap['planner_answer']);
    }

    public function test_agentic_planner_gives_up_only_after_the_single_self_correction(): void
    {
        Bus::fake();

        $generator = $this->createStub(EntryGeneratorService::class);
        $generator->method('getCollectionsCatalog')->willReturn([
            ['handle' => 'pages', 'title' => 'Pages', 'blueprints' => [['handle' => 'page', 'title' => 'Page']]],
        ]);

        $decorator = new PlanEntryDecorator($generator);

        $aiService = $this->createMock(AbstractAiService::class);
        $aiService->method('supportsChatTools')->willReturn(true);

        // Model bails twice. The nudge fires once (round 1→2); the second bail is
        // accepted and surfaced. It must be called exactly twice — no infinite loop.
        $aiService->expects($this->exactly(2))
            ->method('createChatCompletion')
            ->willReturn([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Cannot proceed: no entry named "Ghost Page" exists.',
                    ],
                ]],
            ]);

        $planner = new EntryGenerationPlanner(
            $aiService,
            $generator,
            new PromptUrlFetcher,
            null,
            $this->batch,
            $decorator,
        );

        $sid = $this->batch->initPlanningSession(
            'default',
            null,
            'update the Ghost Page',
            true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        (new PlanEntriesJob($sid))->handle($this->batch, $planner, $generator, $decorator);

        $session = $this->batch->getSession($sid);
        $this->assertSame('planning_failed', $session['planning_status']);
        $this->assertStringContainsString('Ghost Page', $session['planner_error']);
        Bus::assertNotDispatched(GeneratePlannedEntryJob::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     * @param  array<int, mixed>  $capturedMessages  Filled with each createChatCompletion $messages arg.
     */
    private function mockAi(array $responses, array &$capturedMessages): AbstractAiService
    {
        $ai = $this->createStub(AbstractAiService::class);
        $ai->method('supportsChatTools')->willReturn(true);
        $i = 0;
        $ai->method('createChatCompletion')->willReturnCallback(
            function ($messages) use (&$capturedMessages, &$i, $responses) {
                $capturedMessages[] = $messages;

                return $responses[min($i++, count($responses) - 1)];
            },
        );

        return $ai;
    }

    /** @param array<int, array<string, mixed>> $toolCalls */
    private function assistantToolCalls(array $toolCalls): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls]]]];
    }

    private function assistantText(string $text): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]];
    }

    /** An empty completion — no content, no tool calls (provider hiccup). */
    private function assistantEmpty(): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => null]]]];
    }

    /** @param array<string, mixed> $args */
    private function toolCall(string $id, string $name, array $args): array
    {
        return ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]];
    }

    private function catalogStub(): EntryGeneratorService
    {
        $generator = $this->createStub(EntryGeneratorService::class);
        $generator->method('getCollectionsCatalog')->willReturn([
            ['handle' => 'pages', 'title' => 'Pages', 'blueprints' => [['handle' => 'page', 'title' => 'Page']]],
        ]);

        return $generator;
    }

    private function makePlanner(AbstractAiService $ai, EntryGeneratorService $generator): EntryGenerationPlanner
    {
        return new EntryGenerationPlanner(
            $ai, $generator, new PromptUrlFetcher, null, $this->batch, new PlanEntryDecorator($generator),
        );
    }

    private function initAgenticSession(string $prompt): string
    {
        return $this->batch->initPlanningSession(
            'default', null, $prompt, true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );
    }

    public function test_first_turn_message_array_is_unchanged(): void
    {
        Bus::fake();
        $captured = [];
        $generator = $this->catalogStub();

        $sid = $this->initAgenticSession('Write the contact page.');
        // End via a create so the loop finishes without the self-correction nudge.
        $ai = $this->mockAi([
            $this->assistantToolCalls([$this->toolCall('c', 'create_entry_job', [
                'collection' => 'pages', 'blueprint' => 'page', 'label' => 'Contact', 'prompt' => 'Write the contact page.',
            ])]),
            $this->assistantText('Done.'),
        ], $captured);

        $this->makePlanner($ai, $generator)->planAgentic($sid);

        // Turn 1: exactly [system, user]; user carries the catalog + the prompt.
        $first = $captured[0];
        $this->assertCount(2, $first);
        $this->assertSame('system', $first[0]['role']);
        $this->assertSame('user', $first[1]['role']);
        $this->assertStringContainsString('Available collections and blueprints (JSON):', $first[1]['content']);
        $this->assertStringContainsString("User request:\nWrite the contact page.", $first[1]['content']);
    }

    public function test_follow_up_seeds_messages_from_transcript_and_appends_summary_turn(): void
    {
        Bus::fake();
        $generator = $this->catalogStub();

        // Turn 1 state: an update to an existing entry, recorded in the transcript.
        $sid = $this->initAgenticSession('Update the Home page hero.');
        $this->batch->addPlannedEntry($sid, [
            'id' => 'row1', 'collection' => 'pages', 'blueprint' => 'page', 'prompt' => 'p',
            'label' => 'Home', 'collection_title' => 'Pages', 'blueprint_title' => 'Page', 'entry_id' => 'home-123',
        ], 5);
        $this->batch->appendAssistantTurn($sid, 'Done — updated “Home”.', ['row1'], 'summary');
        $this->batch->markPlanningComplete($sid);
        $this->batch->reopenForFollowUp($sid, 'Add a FAQ section to this entry.', 1);

        $captured = [];
        // Follow-up just answers, to keep the assertion on the INPUT messages.
        $ai = $this->mockAi([
            $this->assistantToolCalls([$this->toolCall('a', 'answer_question', ['answer' => 'Added.'])]),
        ], $captured);

        $this->makePlanner($ai, $generator)->planAgentic($sid);

        $msgs = $captured[0];
        // [system, user(turn1), assistant(turn1 summary + entry refs), user(turn2 w/ catalog)]
        $this->assertSame('system', $msgs[0]['role']);
        $this->assertSame('user', $msgs[1]['role']);
        $this->assertStringContainsString('Update the Home page hero.', $msgs[1]['content']);
        $this->assertSame('assistant', $msgs[2]['role']);
        $this->assertStringContainsString('Done — updated “Home”.', $msgs[2]['content']);
        $this->assertStringContainsString('home-123', $msgs[2]['content']); // entry ref for "this entry"
        $this->assertSame('user', $msgs[3]['role']);
        $this->assertStringContainsString('Available collections and blueprints (JSON):', $msgs[3]['content']);
        $this->assertStringContainsString('Add a FAQ section to this entry.', $msgs[3]['content']);
    }

    public function test_agentic_success_appends_summary_turn_with_entry_ids(): void
    {
        Bus::fake();
        $captured = [];
        $generator = $this->catalogStub();
        $ai = $this->mockAi([
            $this->assistantToolCalls([$this->toolCall('c', 'create_entry_job', [
                'collection' => 'pages', 'blueprint' => 'page', 'label' => 'Contact', 'prompt' => 'Write the contact page.',
            ])]),
            $this->assistantText('Done.'),
        ], $captured);

        $sid = $this->initAgenticSession('Write the contact page.');
        $this->makePlanner($ai, $generator)->planAgentic($sid);

        $transcript = $this->batch->getSession($sid)['transcript'];
        $summary = end($transcript);
        $this->assertSame('assistant', $summary['role']);
        $this->assertSame('summary', $summary['kind']);
        $this->assertCount(1, $summary['entry_ids']);
        $this->assertStringContainsString('Contact', $summary['text']);
    }

    public function test_empty_response_is_recovered_by_nudging_then_succeeds(): void
    {
        Bus::fake();
        $captured = [];
        $generator = $this->catalogStub();
        // Round 1: empty completion (no content, no tool calls) — must NOT fail.
        // Round 2 (after nudge): the create. Round 3: final text.
        $ai = $this->mockAi([
            $this->assistantEmpty(),
            $this->assistantToolCalls([$this->toolCall('c', 'create_entry_job', [
                'collection' => 'pages', 'blueprint' => 'page', 'label' => 'Contact', 'prompt' => 'Write the contact page.',
            ])]),
            $this->assistantText('Done.'),
        ], $captured);

        $sid = $this->initAgenticSession('Write the contact page.');
        (new PlanEntriesJob($sid))->handle($this->batch, $this->makePlanner($ai, $generator), $generator, new PlanEntryDecorator($generator));

        $session = $this->batch->getSession($sid);
        $this->assertSame('planned', $session['planning_status']);
        $this->assertNull($session['planner_error']);
        $this->assertCount(1, $session['entry_order']);
        Bus::assertDispatchedTimes(GeneratePlannedEntryJob::class, 1);
    }

    public function test_ignored_forced_fetch_then_empty_recovers_and_creates(): void
    {
        Bus::fake();
        $captured = [];
        $generator = $this->catalogStub();
        // URL prompt → round 1 forces fetch_page_content, but the model ignores it
        // and returns empty. We must relax to auto + nudge, not get stuck. Round 2
        // then creates the entry.
        $ai = $this->mockAi([
            $this->assistantEmpty(),
            $this->assistantToolCalls([$this->toolCall('c', 'create_entry_job', [
                'collection' => 'pages', 'blueprint' => 'page', 'label' => 'Banquets',
                'prompt' => 'Create a page based on https://www.example.com/events/banquets',
            ])]),
            $this->assistantText('Done.'),
        ], $captured);

        $sid = $this->initAgenticSession('erstelle mir eine neue seite basierend auf: https://www.example.com/events/banquets');
        (new PlanEntriesJob($sid))->handle($this->batch, $this->makePlanner($ai, $generator), $generator, new PlanEntryDecorator($generator));

        $session = $this->batch->getSession($sid);
        $this->assertSame('planned', $session['planning_status']);
        $this->assertNull($session['planner_error']);
        $this->assertCount(1, $session['entry_order']);
        Bus::assertDispatchedTimes(GeneratePlannedEntryJob::class, 1);
    }

    public function test_empty_response_after_dispatch_finishes_successfully(): void
    {
        Bus::fake();
        $captured = [];
        $generator = $this->catalogStub();
        // Create, then the model returns empty instead of a closing summary → still a success.
        $ai = $this->mockAi([
            $this->assistantToolCalls([$this->toolCall('c', 'create_entry_job', [
                'collection' => 'pages', 'blueprint' => 'page', 'label' => 'Contact', 'prompt' => 'Write the contact page.',
            ])]),
            $this->assistantEmpty(),
        ], $captured);

        $sid = $this->initAgenticSession('Write the contact page.');
        (new PlanEntriesJob($sid))->handle($this->batch, $this->makePlanner($ai, $generator), $generator, new PlanEntryDecorator($generator));

        $session = $this->batch->getSession($sid);
        $this->assertSame('planned', $session['planning_status']);
        $this->assertNull($session['planner_error']);
        $this->assertCount(1, $session['entry_order']);
    }

    public function test_persistent_empty_responses_fail_gracefully(): void
    {
        Bus::fake();
        $captured = [];
        $generator = $this->catalogStub();
        // The model only ever returns empty completions → fail with a clear message,
        // not an infinite loop.
        $ai = $this->mockAi([$this->assistantEmpty()], $captured);

        $sid = $this->initAgenticSession('Write the contact page.');
        (new PlanEntriesJob($sid))->handle($this->batch, $this->makePlanner($ai, $generator), $generator, new PlanEntryDecorator($generator));

        $session = $this->batch->getSession($sid);
        $this->assertSame('planning_failed', $session['planning_status']);
        $this->assertStringContainsString('empty response', $session['planner_error']);
        Bus::assertNotDispatched(GeneratePlannedEntryJob::class);
    }

    public function test_answer_question_appends_answer_turn(): void
    {
        Bus::fake();
        $captured = [];
        $generator = $this->catalogStub();
        $ai = $this->mockAi([
            $this->assistantToolCalls([$this->toolCall('a', 'answer_question', ['answer' => 'It is the Home page.'])]),
        ], $captured);

        $sid = $this->initAgenticSession('Which page has the hero?');
        $this->makePlanner($ai, $generator)->planAgentic($sid);

        $transcript = $this->batch->getSession($sid)['transcript'];
        $turn = end($transcript);
        $this->assertSame('answer', $turn['kind']);
        $this->assertSame('It is the Home page.', $turn['text']);
        $this->assertSame([], $turn['entry_ids']);
    }

    public function test_bail_on_a_question_is_nudged_into_an_answer(): void
    {
        Bus::fake();

        $generator = $this->createStub(EntryGeneratorService::class);
        $generator->method('getCollectionsCatalog')->willReturn([
            ['handle' => 'events', 'title' => 'Events', 'blueprints' => [['handle' => 'event', 'title' => 'Event']]],
        ]);
        $generator->method('findEntriesShortlist')->willReturn([
            ['id' => 'evt-1', 'title' => 'Summer Fair', 'slug' => 'summer-fair', 'collection' => 'events'],
        ]);

        // Round 1: model wrongly bails on a read-only question without searching.
        // Round 2 (after the nudge): it searches. Round 3: it answers.
        $captured = [];
        $ai = $this->mockAi([
            $this->assistantText('Cannot proceed: the user did not provide a title or topic in their last message.'),
            $this->assistantToolCalls([$this->toolCall('f1', 'find_entries', ['query' => 'events', 'collection' => 'events'])]),
            $this->assistantToolCalls([$this->toolCall('a1', 'answer_question', ['answer' => 'The biggest Event entry is Summer Fair.'])]),
        ], $captured);

        $sid = $this->initAgenticSession('which is the biggest entry in Event ?');
        $this->makePlanner($ai, $generator)->planAgentic($sid);

        $session = $this->batch->getSession($sid);
        $this->assertSame('planned', $session['planning_status']);
        $this->assertNull($session['planner_error']);
        $this->assertStringContainsString('Summer Fair', $session['planner_answer']);

        $turn = end($session['transcript']);
        $this->assertSame('answer', $turn['kind']);
        Bus::assertNotDispatched(GeneratePlannedEntryJob::class);
    }

    public function test_plain_text_answer_question_is_treated_as_success(): void
    {
        Bus::fake();

        $generator = $this->createStub(EntryGeneratorService::class);
        $generator->method('getCollectionsCatalog')->willReturn([
            ['handle' => 'news', 'title' => 'News', 'blueprints' => [['handle' => 'news', 'title' => 'News']]],
        ]);
        $generator->method('searchEntryContent')->willReturn([
            ['id' => 'a005745a-5620-4209-919f-0e85c1cf1b96', 'title' => 'Extended Company Report', 'slug' => 'top', 'collection' => 'news', 'snippet' => 'Long body…'],
        ]);

        $plainAnswer = 'answer_question:{"answer": "The longest entry is Extended Company Report (id a005745a-5620-4209-919f-0e85c1cf1b96)."}';

        $capturedMessages = [];
        $ai = $this->mockAi([
            $this->assistantToolCalls([$this->toolCall('s1', 'search_entry_content', ['query' => 'longest'])]),
            $this->assistantText($plainAnswer),
        ], $capturedMessages);

        $sid = $this->batch->initPlanningSession(
            'default',
            null,
            'can you find the longest entry ?',
            true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        $planner = new EntryGenerationPlanner(
            $ai,
            $generator,
            new PromptUrlFetcher,
            null,
            $this->batch,
            new PlanEntryDecorator($generator),
        );

        $planner->planAgentic($sid);

        $session = $this->batch->getSession($sid);
        $this->assertSame('planned', $session['planning_status']);
        $this->assertNull($session['planner_error']);
        $this->assertStringContainsString('a005745a', $session['planner_answer']);

        $turn = end($session['transcript']);
        $this->assertSame('answer', $turn['kind']);
        $this->assertStringContainsString('a005745a', $turn['text']);
        Bus::assertNotDispatched(GeneratePlannedEntryJob::class);
    }

    public function test_bare_plain_text_answer_after_searching_is_an_answer_not_an_error(): void
    {
        Bus::fake();

        // Regression: "do we have this on our website already?" — the model
        // searched with find_entries, then answered as BARE plain text (no
        // answer_question call, no "answer_question:{…}" format). That answer
        // must surface as a successful answer turn, never as an error panel.
        $generator = $this->createStub(EntryGeneratorService::class);
        $generator->method('getCollectionsCatalog')->willReturn([
            ['handle' => 'pages', 'title' => 'Pages', 'blueprints' => [['handle' => 'page', 'title' => 'Page']]],
        ]);
        $generator->method('findEntriesShortlist')->willReturn([
            ['id' => '8da2a1d6', 'title' => 'Services & Pricing', 'slug' => 'services-pricing', 'collection' => 'pages'],
        ]);

        $captured = [];
        $ai = $this->mockAi([
            $this->assistantToolCalls([$this->toolCall('f1', 'find_entries', ['query' => 'Services'])]),
            $this->assistantText('Yes — the page “Services & Pricing” already exists on your site (entry id 8da2a1d6).'),
        ], $captured);

        $sid = $this->initAgenticSession('do we have this on our website already ?');
        $this->makePlanner($ai, $generator)->planAgentic($sid);

        $session = $this->batch->getSession($sid);
        $this->assertSame('planned', $session['planning_status']);
        $this->assertNull($session['planner_error']);
        $this->assertStringContainsString('8da2a1d6', $session['planner_answer']);

        // No nudge round: the model DID use a tool, so its text is accepted directly.
        $this->assertCount(2, $captured);

        $turn = end($session['transcript']);
        $this->assertSame('answer', $turn['kind']);
        Bus::assertNotDispatched(GeneratePlannedEntryJob::class);
    }

    public function test_plain_text_answer_after_a_runner_tool_is_not_nudged(): void
    {
        Bus::fake();

        // Regression: "how many assets do we have?" — the model used list_assets
        // (a runner-registered read tool, NOT find_entries) and then answered in
        // plain text. The premature-bail nudge must not fire ("you have not used
        // any tools") — it discarded the good answer and surfaced the model's
        // meta-reply ("I have already answered…") instead.
        $captured = [];
        $ai = $this->mockAi([
            $this->assistantToolCalls([$this->toolCall('l1', 'list_assets', [])]),
            $this->assistantText('We have 467 assets in total.'),
        ], $captured);

        $sid = $this->initAgenticSession('how many assets do we have in total ?');
        $this->makePlanner($ai, $this->catalogStub())->planAgentic($sid);

        $session = $this->batch->getSession($sid);
        $this->assertSame('planned', $session['planning_status']);
        $this->assertNull($session['planner_error']);
        $this->assertStringContainsString('467', $session['planner_answer']);

        // Exactly two rounds — no nudge round in between.
        $this->assertCount(2, $captured);

        $turn = end($session['transcript']);
        $this->assertSame('answer', $turn['kind']);
        Bus::assertNotDispatched(GeneratePlannedEntryJob::class);
    }

    public function test_failure_appends_error_turn(): void
    {
        Bus::fake();

        $generator = $this->catalogStub();
        $ai = $this->createStub(AbstractAiService::class);
        $ai->method('supportsChatTools')->willReturn(true);
        $ai->method('createChatCompletion')->willThrowException(new \RuntimeException('LLM exploded'));

        $sid = $this->initAgenticSession('Make something.');
        (new PlanEntriesJob($sid))->handle($this->batch, $this->makePlanner($ai, $generator), $generator, new PlanEntryDecorator($generator));

        $transcript = $this->batch->getSession($sid)['transcript'];
        $turn = end($transcript);
        $this->assertSame('error', $turn['kind']);
        $this->assertSame('LLM exploded', $turn['text']);
    }

    public function test_planner_failure_marks_planning_failed(): void
    {
        Bus::fake();

        $generator = $this->createStub(EntryGeneratorService::class);
        $generator->method('getCollectionsCatalog')->willReturn([
            ['handle' => 'pages', 'title' => 'Pages', 'blueprints' => [['handle' => 'page', 'title' => 'Page']]],
        ]);

        $aiService = $this->createStub(AbstractAiService::class);
        $aiService->method('supportsChatTools')->willReturn(true);
        $aiService->method('createChatCompletion')->willThrowException(new \RuntimeException('LLM exploded'));

        $decorator = new PlanEntryDecorator($generator);

        $planner = new EntryGenerationPlanner(
            $aiService,
            $generator,
            new PromptUrlFetcher,
            null,
            $this->batch,
            $decorator,
        );

        $sid = $this->batch->initPlanningSession(
            'default',
            null,
            'Make some entries.',
            true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        (new PlanEntriesJob($sid))->handle($this->batch, $planner, $generator, $decorator);

        $session = $this->batch->getSession($sid);
        $this->assertSame('planning_failed', $session['planning_status']);
        $this->assertSame('LLM exploded', $session['planner_error']);
        Bus::assertNotDispatched(GeneratePlannedEntryJob::class);
    }

    public function test_cancelled_session_marks_planning_failed_without_calling_planner(): void
    {
        Bus::fake();

        $generator = $this->createMock(EntryGeneratorService::class);
        $generator->expects($this->never())->method('getCollectionsCatalog');

        $aiService = $this->createMock(AbstractAiService::class);
        $aiService->expects($this->never())->method('createChatCompletion');

        $decorator = new PlanEntryDecorator($generator);

        $planner = new EntryGenerationPlanner(
            $aiService,
            $generator,
            new PromptUrlFetcher,
            null,
            $this->batch,
            $decorator,
        );

        $sid = $this->batch->initPlanningSession(
            'default',
            null,
            'p',
            true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );
        $this->batch->cancelSession($sid);

        (new PlanEntriesJob($sid))->handle($this->batch, $planner, $generator, $decorator);

        $session = $this->batch->getSession($sid);
        $this->assertSame('planning_failed', $session['planning_status']);
        Bus::assertNotDispatched(GeneratePlannedEntryJob::class);
    }
}

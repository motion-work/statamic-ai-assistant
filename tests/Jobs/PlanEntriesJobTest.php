<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Jobs;

use BoldWeb\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use BoldWeb\StatamicAiAssistant\Jobs\PlanEntriesJob;
use BoldWeb\StatamicAiAssistant\Services\AbstractAiService;
use BoldWeb\StatamicAiAssistant\Services\EntryGenerationBatchService;
use BoldWeb\StatamicAiAssistant\Services\EntryGenerationPlanner;
use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use BoldWeb\StatamicAiAssistant\Services\PromptUrlFetcher;
use BoldWeb\StatamicAiAssistant\Support\PlanEntryDecorator;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

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

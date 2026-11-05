<?php

namespace MotionWork\StatamicAiAssistant\Tests\Semantic;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;
use MotionWork\StatamicAiAssistant\Jobs\IndexEntryEmbeddingJob;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndex;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndexer;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class EmbeddingIndexingTest extends TestCase
{
    use FakesEmbeddings;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wipeEntries();
        $this->useSemanticSearch();
        $this->site = Site::default()->handle();

        Blueprint::make('entry')->setNamespace('collections.pages')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'body', 'field' => ['type' => 'textarea']],
            ]]]]],
        ])->save();
        Collection::make('pages')->title('Pages')->sites([$this->site])->save();
    }

    protected function tearDown(): void
    {
        $this->wipeEntries();
        $this->tearDownSemanticSearch();
        parent::tearDown();
    }

    public function test_a_rebuild_only_sends_changed_entries_and_drops_deleted_ones(): void
    {
        $first = $this->entry('Pflegehinweis', 'Holz ölen');
        $second = $this->entry('Garantie', 'Reparaturen');

        $this->assertSame(['indexed' => 2, 'unchanged' => 0, 'removed' => 0], $this->indexer()->buildSite($this->site));
        $this->assertCount(1, $this->embeddingRequests, 'Both entries go in one batch.');

        $this->fakeEmbeddings();
        $this->assertSame(['indexed' => 0, 'unchanged' => 2, 'removed' => 0], $this->indexer()->buildSite($this->site));
        $this->assertSame([], $this->embeddingRequests, 'Unchanged entries are not sent again.');

        Entry::find($first)->set('body', 'Möbel mit Öl pflegen')->saveQuietly();
        Entry::find($second)->deleteQuietly();
        $this->assertSame(['indexed' => 1, 'unchanged' => 0, 'removed' => 1], $this->indexer()->buildSite($this->site));
        $this->assertSame([$first], array_keys((new EmbeddingIndex($this->site))->hashes('openai/text-embedding-3-small', self::DIMENSIONS)));
    }

    public function test_the_request_carries_dimensions_privacy_and_reports_its_cost(): void
    {
        Event::fake([AiCompletionFinished::class]);
        $this->entry('Pflegehinweis', 'Holz ölen');

        $this->indexer()->buildSite($this->site);

        $payload = $this->embeddingRequests[0];
        $this->assertSame('openai/text-embedding-3-small', $payload['model']);
        $this->assertSame(self::DIMENSIONS, $payload['dimensions']);
        $this->assertSame('deny', $payload['provider']['data_collection']);
        Event::assertDispatched(AiCompletionFinished::class, fn (AiCompletionFinished $e) => $e->task === AiTask::Embedding
            && $e->cost() === 0.0000002
            && ! $e->usedFallback());
    }

    public function test_saving_queues_a_reindex_only_once_the_index_is_built(): void
    {
        Queue::fake();
        $this->entry('Pflegehinweis', 'Holz ölen');
        Queue::assertNotPushed(IndexEntryEmbeddingJob::class);

        $this->indexer()->buildSite($this->site);
        $id = $this->entry('Garantie', 'Reparaturen');

        Queue::assertPushed(IndexEntryEmbeddingJob::class, fn (IndexEntryEmbeddingJob $job) => $job->entryId === $id);
    }

    public function test_deleting_removes_the_vector_at_once(): void
    {
        $id = $this->entry('Pflegehinweis', 'Holz ölen');
        $this->indexer()->buildSite($this->site);

        Entry::find($id)->delete();

        $this->assertSame([], (new EmbeddingIndex($this->site))->hashes('openai/text-embedding-3-small', self::DIMENSIONS));
    }

    public function test_index_problems_never_affect_saving_or_deleting_an_entry(): void
    {
        $id = $this->entry('Pflegehinweis', 'Holz ölen');
        $this->indexer()->buildSite($this->site);
        file_put_contents($this->indexPath.'/'.$this->site.'/manifest.json', '{broken');

        Entry::find($id)->set('body', 'neu')->save();
        Entry::find($id)->delete();

        $this->assertNull(Entry::find($id));
    }

    public function test_the_reindex_job_swallows_a_refused_request(): void
    {
        $id = $this->entry('Pflegehinweis', 'Holz ölen');
        $this->indexer()->buildSite($this->site);
        Entry::find($id)->set('body', 'neu')->saveQuietly();
        $this->fakeEmbeddings(402);

        (new IndexEntryEmbeddingJob($id))->handle($this->indexer());

        $this->assertCount(1, (new EmbeddingIndex($this->site))->manifest()['entries'], 'The old vector stays.');
    }

    private function indexer(): EmbeddingIndexer
    {
        return app(EmbeddingIndexer::class);
    }

    private function entry(string $title, string $body): string
    {
        $entry = Entry::make()->collection('pages')->locale($this->site)->slug(str($title)->slug())->data(['title' => $title, 'body' => $body]);
        $entry->save();

        return (string) $entry->id();
    }
}

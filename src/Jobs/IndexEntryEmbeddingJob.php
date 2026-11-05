<?php

namespace MotionWork\StatamicAiAssistant\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndexer;
use MotionWork\StatamicAiAssistant\Semantic\SemanticSearch;
use Statamic\Facades\Entry;

/**
 * Re-embeds one saved entry (skipped when its text did not change). Queued by
 * SyncEntryEmbedding, so saving an entry never waits for the model. A failure
 * only leaves the old vector in place; search keeps working.
 */
class IndexEntryEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $entryId)
    {
        $this->onQueue(EmbeddingIndexer::queue());
    }

    public function handle(EmbeddingIndexer $indexer): void
    {
        $entry = Entry::find($this->entryId);

        if ($entry === null) {
            return;
        }

        try {
            $indexer->indexEntry($entry);
        } catch (\Throwable $e) {
            // Deliberate: the entry is saved; a stale vector only weakens search.
            SemanticSearch::warnOnce('index entry', $e);
        }
    }
}

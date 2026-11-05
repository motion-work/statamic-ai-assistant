<?php

namespace MotionWork\StatamicAiAssistant\Semantic;

use MotionWork\StatamicAiAssistant\Jobs\IndexEntryEmbeddingJob;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;

/**
 * Keeps the embedding index fresh: a saved entry is re-embedded on the queue,
 * a deleted one leaves the index at once. Only sites whose index was built
 * are kept up to date (building is a deliberate step, see the artisan
 * command). Nothing here may affect saving or deleting the entry itself.
 */
class SyncEntryEmbedding
{
    public function __construct(
        private readonly SemanticSearch $semantic,
        private readonly EmbeddingIndexer $indexer,
    ) {}

    public function handle(EntrySaved|EntryDeleted $event): void
    {
        try {
            $entry = $event->entry;
            $site = (string) $entry->locale();

            if ($this->semantic->providerReason() !== null || ! (new EmbeddingIndex($site))->exists()) {
                return;
            }

            if ($event instanceof EntryDeleted) {
                $this->indexer->removeEntry((string) $entry->id(), $site);

                return;
            }

            IndexEntryEmbeddingJob::dispatch((string) $entry->id());
        } catch (\Throwable $e) {
            // Deliberate: an index problem must never fail an entry save.
            SemanticSearch::warnOnce('sync', $e);
        }
    }
}

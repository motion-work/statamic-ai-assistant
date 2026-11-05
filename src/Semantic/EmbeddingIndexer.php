<?php

namespace MotionWork\StatamicAiAssistant\Semantic;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\EmbedsText;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;

/**
 * Keeps a site's embedding index in step with its entries. Only entries whose
 * text hash changed are sent to the model, in batches written one by one, so
 * an interrupted build resumes where it stopped.
 */
class EmbeddingIndexer
{
    /** Inputs per embeddings request. */
    private const BATCH = 32;

    public function __construct(
        private readonly AbstractAiService $ai,
        private readonly SemanticSearch $semantic,
    ) {}

    /** The queue that index updates run on. */
    public static function queue(): string
    {
        return (string) (config('statamic-ai-assistant.semantic_search.queue')
            ?: config('statamic-ai-assistant.entry_generator_batch.queue', 'default'));
    }

    /**
     * Embed every changed entry of $site and drop deleted ones.
     *
     * @param  callable(int $done, int $total): void|null  $progress
     * @return array{indexed: int, unchanged: int, removed: int}
     */
    public function buildSite(string $site, ?callable $progress = null): array
    {
        $embedder = $this->embedder();
        $index = new EmbeddingIndex($site);
        $stored = $index->hashes((string) $embedder->embeddingModel(), $embedder->embeddingDimensions());

        $pending = [];
        $present = [];
        foreach (Entry::query()->where('site', $site)->get() as $entry) {
            $item = $this->item($entry);
            $present[$item['id']] = true;
            if ($item['text'] !== '' && ($stored[$item['id']] ?? null) !== $item['hash']) {
                $pending[$item['id']] = $item;
            }
        }

        $done = 0;
        foreach (array_chunk($pending, self::BATCH, true) as $batch) {
            $this->embedBatch($index, $batch);
            $done += count($batch);
            $progress && $progress($done, count($pending));
        }

        $removed = array_values(array_diff(array_keys($stored), array_keys($present)));
        $index->remove($removed);

        return ['indexed' => $done, 'unchanged' => count($present) - count($pending), 'removed' => count($removed)];
    }

    /** Re-embed one entry when its text changed; true when it was sent. */
    public function indexEntry(StatamicEntry $entry): bool
    {
        $embedder = $this->embedder();
        $index = new EmbeddingIndex((string) $entry->locale());
        $item = $this->item($entry);
        $stored = $index->hashes((string) $embedder->embeddingModel(), $embedder->embeddingDimensions());

        if ($item['text'] === '' || ($stored[$item['id']] ?? null) === $item['hash']) {
            return false;
        }

        $this->embedBatch($index, [$item['id'] => $item]);

        return true;
    }

    public function removeEntry(string $id, string $site): void
    {
        (new EmbeddingIndex($site))->remove([$id]);
    }

    private function embedder(): EmbedsText
    {
        $reason = $this->semantic->providerReason();

        if ($reason !== null || ! $this->ai instanceof EmbedsText) {
            throw new \RuntimeException('Semantic search cannot index entries ('.($reason ?? SemanticSearch::PROVIDER).').');
        }

        return $this->ai;
    }

    /**
     * @param  array<string, array{id: string, collection: string, hash: string, text: string}>  $batch
     */
    private function embedBatch(EmbeddingIndex $index, array $batch): void
    {
        $embedder = $this->embedder();
        $vectors = $embedder->embed(array_values(array_column($batch, 'text')));

        $items = [];
        foreach (array_values($batch) as $i => $item) {
            $items[$item['id']] = ['collection' => $item['collection'], 'hash' => $item['hash'], 'vector' => $vectors[$i]];
        }

        $index->upsert($items, (string) $embedder->embeddingModel(), $embedder->embeddingDimensions());
    }

    /**
     * @return array{id: string, collection: string, hash: string, text: string}
     */
    private function item(StatamicEntry $entry): array
    {
        $embedder = $this->embedder();
        $text = EntryEmbeddingText::for($entry);

        return [
            'id' => (string) $entry->id(),
            'collection' => (string) ($entry->collectionHandle() ?? ''),
            'hash' => EntryEmbeddingText::hash($text, (string) $embedder->embeddingModel(), $embedder->embeddingDimensions()),
            'text' => $text,
        ];
    }
}

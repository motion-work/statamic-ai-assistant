<?php

namespace MotionWork\StatamicAiAssistant\Semantic;

use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Services\EntryContentSearch;
use MotionWork\StatamicAiAssistant\Services\EntrySearch;
use MotionWork\StatamicAiAssistant\Support\SearchText;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;

/**
 * The AI agent's entry discovery (find_entries, search_entry_content and the
 * read tools' lookups) with semantic matches merged into the keyword results.
 * The result shapes are EntrySearch's own.
 *
 * Whenever SemanticSearch::nearest() returns null (see there) every method
 * returns exactly what EntrySearch returns. Exact reference resolution
 * (ReferenceValueMapper) and the @-mention picker keep plain EntrySearch.
 */
class SemanticEntrySearch extends EntrySearch
{
    /** Semantic candidates considered per search. */
    private const SEMANTIC_LIMIT = 50;

    /** Keyword score from which a title holds the whole query (see EntrySearch::rank()). */
    private const PINNED_SCORE = 200;

    /** Keyword score from which every query word is in the title or slug (coverage bonus). */
    private const FULL_MATCH_SCORE = 50;

    public function __construct(private ?SemanticSearch $semantic = null) {}

    public function findEntries(?string $collectionHandle, string $query, int $limit, int $offset = 0): array
    {
        $tokens = SearchText::tokens($query);
        $site = SemanticSearch::currentSite();
        $hits = $tokens === [] ? null : $this->semantic()->nearest($site, $query, $collectionHandle, self::SEMANTIC_LIMIT);

        if ($hits === null) {
            return parent::findEntries($collectionHandle, $query, $limit, $offset);
        }

        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $candidates = $this->candidates($tokens, $site, $collectionHandle);
        $ranked = $this->rank($candidates, $query, $tokens);
        $entries = $this->withSemanticEntries($this->byId(array_column($ranked, 'entry')), $hits, $site);

        $order = array_values(array_filter($this->order($ranked, $hits, $query), fn ($id) => isset($entries[$id])));

        $out = $this->page($this->mapEntryRows(array_map(fn ($id) => $entries[$id], array_slice($order, $offset, $limit))), count($order), $limit, $offset);
        if ($candidates->count() >= self::SEARCH_SCAN_LIMIT) {
            $out['note'] = 'Only the '.self::SEARCH_SCAN_LIMIT.' most recently updated keyword matches were ranked, plus entries similar in meaning. Narrow the query or pass a collection handle.';
        }

        return $out;
    }

    public function searchEntryContent(?string $collectionHandle, string $query, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $site = SemanticSearch::currentSite();
        $hits = $this->semantic()->nearest($site, $query, $collectionHandle, self::SEMANTIC_LIMIT);
        $keyword = parent::searchEntryContent($collectionHandle, $query, $hits === null ? $limit : 50);

        if ($hits === null) {
            return $keyword;
        }

        $rows = array_column($keyword, null, 'id');
        foreach ($this->withSemanticEntries([], $hits, $site) as $id => $entry) {
            $rows[$id] ??= $this->contentRow($entry);
        }

        $order = SearchFusion::merge(array_column($keyword, 'id'), array_column($hits, 'id'));

        return array_slice(array_values(array_filter(array_map(fn ($id) => $rows[$id] ?? null, $order))), 0, $limit);
    }

    /**
     * Keyword matches holding every query word are merged with the semantic
     * ranking; partial ones (the "any word" relaxation, e.g. only "wie" of
     * "Wie bezahle ich?") are weak evidence and follow the merged list.
     *
     * @param  list<array{entry: StatamicEntry, score: int}>  $ranked
     * @param  list<array{id: string, score: float}>  $hits
     * @return list<string>
     */
    private function order(array $ranked, array $hits, string $query): array
    {
        $ids = fn (array $rows) => array_map(fn ($row) => (string) $row['entry']->id(), $rows);
        $full = array_values(array_filter($ranked, fn ($row) => $row['score'] >= self::FULL_MATCH_SCORE));
        $merged = SearchFusion::merge($ids($full), array_column($hits, 'id'), $this->pinnedIds($ranked, $query));

        return array_values(array_unique([...$merged, ...$ids($ranked)]));
    }

    private function semantic(): SemanticSearch
    {
        return $this->semantic ??= app(SemanticSearch::class);
    }

    /**
     * @param  iterable<StatamicEntry>  $entries
     * @return array<string, StatamicEntry>
     */
    private function byId(iterable $entries): array
    {
        $byId = [];
        foreach ($entries as $entry) {
            $byId[(string) $entry->id()] = $entry;
        }

        return $byId;
    }

    /**
     * $entries plus the semantic hits not in it yet, loaded from $site.
     *
     * @param  array<string, StatamicEntry>  $entries
     * @param  list<array{id: string, score: float}>  $hits
     * @return array<string, StatamicEntry>
     */
    private function withSemanticEntries(array $entries, array $hits, string $site): array
    {
        $missing = array_values(array_diff(array_column($hits, 'id'), array_keys($entries)));

        if ($missing !== []) {
            $entries += $this->byId(Entry::query()->whereIn('id', $missing)->where('site', $site)->get());
        }

        return $entries;
    }

    /**
     * Keyword matches whose title holds the whole query, or whose slug is it.
     *
     * @param  list<array{entry: StatamicEntry, score: int}>  $ranked
     * @return list<string>
     */
    private function pinnedIds(array $ranked, string $query): array
    {
        $slug = Str::slug($query);

        return array_values(array_map(
            fn ($row) => (string) $row['entry']->id(),
            array_filter($ranked, fn ($row) => $row['score'] >= self::PINNED_SCORE || (string) $row['entry']->slug() === $slug),
        ));
    }

    /**
     * A search_entry_content row for an entry found by meaning only.
     *
     * @return array{id: string, title: string, slug: string, collection: string, snippet: string}
     */
    private function contentRow(StatamicEntry $entry): array
    {
        return [
            'id' => (string) $entry->id(),
            'title' => (string) ($entry->value('title') ?? ''),
            'slug' => (string) ($entry->slug() ?? ''),
            'collection' => (string) ($entry->collectionHandle() ?? ''),
            'snippet' => Str::limit((new EntryContentSearch)->flattenEntryText($entry), 160),
        ];
    }
}

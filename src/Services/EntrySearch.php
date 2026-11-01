<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\SearchText;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Terms;

/**
 * Finds entries for the AI agent and the planner: by title/slug (tolerant
 * tokenising and ranking) and by body content (flattened text with a snippet).
 */
class EntrySearch
{
    /** Max candidate rows pulled from the store before in-PHP ranking in findEntriesShortlist(). */
    private const SEARCH_SCAN_LIMIT = 200;

    /**
     * Search entries by title/slug — rows only, no pagination info. Convenience
     * wrapper for callers that just need a shortlist (entry finder closures).
     *
     * @return array<int, array{id: string, title: string, slug: string, collection: string, published: bool}>
     */
    public function findEntriesShortlist(?string $collectionHandle, string $query, int $limit): array
    {
        return $this->findEntries($collectionHandle, $query, $limit)['results'];
    }

    /**
     * The entry open in the Control Panel, as an "@" mention.
     * Title is the localization of the site selected in the CP, then any
     * localization, so the chip names an entry the planner can resolve.
     *
     * @return array{id: string, title: string, slug: string, collection: string, collection_title: string}|null
     */
    public function entryMention(string $id): ?array
    {
        $id = trim($id);
        if ($id === '' || $id === 'create') {
            return null;
        }

        $site = Site::selected()?->handle() ?? Site::default()->handle();
        $entry = Entry::query()->where('id', $id)->where('site', $site)->first()
            ?? Entry::query()->where('id', $id)->first();

        if ($entry === null) {
            return null;
        }

        $title = trim((string) ($entry->value('title') ?? ''));
        if ($title === '') {
            $title = trim((string) ($entry->slug() ?? ''));
        }
        if ($title === '') {
            return null;
        }

        $collection = $entry->collection();

        return [
            'id' => (string) $entry->id(),
            'title' => $title,
            'slug' => (string) ($entry->slug() ?? ''),
            'collection' => $collection ? (string) $collection->handle() : '',
            'collection_title' => $collection ? (string) $collection->title() : '',
        ];
    }

    /**
     * Search entries by title/slug for the agentic planner's `find_entries` tool.
     * Kept in this service so it can reuse the same query path as the catalog shortlist.
     *
     * The `pagination` block ({total, limit, offset, has_more} — same contract as
     * cboxdk/statamic-mcp list responses) tells the model when it has NOT seen
     * every match, so it pages or narrows instead of assuming completeness. When
     * ranking had to stop at the scan cap, a `note` says so explicitly — a capped
     * result must never silently read as exhaustive.
     *
     * @return array{results: array<int, array{id: string, title: string, slug: string, collection: string, published: bool}>, pagination: array{total: int, limit: int, offset: int, has_more: bool}, note?: string}
     */
    public function findEntries(?string $collectionHandle, string $query, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $siteHandle = Site::selected()?->handle() ?? Site::default()->handle();

        $tokens = SearchText::tokens($query);

        // No meaningful search terms: preserve the old "most recent" behaviour.
        if ($tokens === []) {
            $rows = $this->mapEntryRows(
                $this->baseQuery($siteHandle, $collectionHandle)->orderBy('updated_at', 'desc')->offset($offset)->limit($limit)->get()
            );

            return $this->page($rows, (int) $this->baseQuery($siteHandle, $collectionHandle)->count(), $limit, $offset);
        }

        $candidates = $this->candidates($tokens, $siteHandle, $collectionHandle);
        $total = $candidates->count();
        $out = $this->page($this->rankAndMapEntries($candidates, $query, $tokens, $limit, $offset), $total, $limit, $offset);

        if ($total >= self::SEARCH_SCAN_LIMIT) {
            $out['note'] = 'Only the '.self::SEARCH_SCAN_LIMIT.' most recently updated matches were ranked; older entries may exist. Narrow the query or pass a collection handle.';
        }

        return $out;
    }

    /**
     * A fresh base query per attempt — Statamic's query builder is stateful,
     * so we rebuild rather than clone.
     */
    private function baseQuery(string $siteHandle, ?string $collectionHandle): mixed
    {
        $q = Entry::query()->where('site', $siteHandle);
        if (is_string($collectionHandle) && $collectionHandle !== '') {
            $q->where('collection', $collectionHandle);
        }

        return $q;
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function candidates(array $tokens, string $siteHandle, ?string $collectionHandle): \Illuminate\Support\Collection
    {
        // Primary narrowing: every token must appear in the title OR slug. This is
        // word-order agnostic and — because tokenising strips connectors like "&",
        // "+", "and", "und", "et" — a query for "Salt Stone" or "Salt and Stone" still
        // finds the entry titled "Salt & Stone". `like` is case-insensitive here.
        $andQuery = $this->baseQuery($siteHandle, $collectionHandle);
        foreach ($tokens as $token) {
            $andQuery->where(function ($qq) use ($token) {
                $qq->where('title', 'like', '%'.$token.'%')
                    ->orWhere('slug', 'like', '%'.$token.'%');
            });
        }
        $candidates = $andQuery->orderBy('updated_at', 'desc')->limit(self::SEARCH_SCAN_LIMIT)->get();

        // Fallback: if requiring all tokens found nothing (a stray or misspelled
        // token), relax to "any token" so we still surface near matches instead of
        // returning zero rows and forcing the agent to give up.
        if ($candidates->isEmpty()) {
            $orQuery = $this->baseQuery($siteHandle, $collectionHandle);
            $orQuery->where(function ($outer) use ($tokens) {
                foreach ($tokens as $token) {
                    $outer->orWhere(function ($qq) use ($token) {
                        $qq->where('title', 'like', '%'.$token.'%')
                            ->orWhere('slug', 'like', '%'.$token.'%');
                    });
                }
            });
            $candidates = $orQuery->orderBy('updated_at', 'desc')->limit(self::SEARCH_SCAN_LIMIT)->get();
        }

        return $candidates;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{results: array<int, array<string, mixed>>, pagination: array{total: int, limit: int, offset: int, has_more: bool}}
     */
    private function page(array $rows, int $total, int $limit, int $offset): array
    {
        return [
            'results' => $rows,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => $offset + count($rows) < $total,
            ],
        ];
    }

    /**
     * Rank candidate entries by how well they match the query, then map the
     * requested page ($offset/$limit over the ranked order) to the tool's row
     * shape. Exact title beats full-phrase-in-title beats per-token coverage;
     * ties keep the incoming (recency) order.
     *
     * @param  \Illuminate\Support\Collection<int, StatamicEntry>  $candidates
     * @param  array<int, string>  $tokens
     * @return array<int, array{id: string, title: string, slug: string, collection: string, published: bool}>
     */
    private function rankAndMapEntries($candidates, string $query, array $tokens, int $limit, int $offset = 0): array
    {
        $queryFold = SearchText::fold($query);
        $tokensFold = array_values(array_filter(array_map(fn ($t) => SearchText::fold($t), $tokens)));

        $ranked = $candidates
            ->map(function ($entry) use ($queryFold, $tokensFold) {
                $titleFold = SearchText::fold((string) ($entry->value('title') ?? ''));
                $slugFold = SearchText::fold((string) ($entry->slug() ?? ''));
                $haystack = trim($titleFold.' '.$slugFold);

                $score = 0;
                if ($queryFold !== '' && $titleFold === $queryFold) {
                    $score += 1000;
                }
                if ($queryFold !== '' && str_contains($titleFold, $queryFold)) {
                    $score += 200;
                }

                $covered = 0;
                foreach ($tokensFold as $token) {
                    if (str_contains($haystack, $token)) {
                        $covered++;
                    }
                }
                $score += $covered * 10;
                if ($tokensFold !== [] && $covered === count($tokensFold)) {
                    $score += 50;
                }

                return ['entry' => $entry, 'score' => $score];
            })
            ->sortByDesc('score') // stable in PHP 8: equal scores keep recency order
            ->slice($offset, $limit)
            ->pluck('entry');

        return $this->mapEntryRows($ranked);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, StatamicEntry>|iterable<StatamicEntry>  $entries
     * @return array<int, array{id: string, title: string, slug: string, collection: string, published: bool}>
     */
    private function mapEntryRows($entries): array
    {
        return collect($entries)
            ->map(fn ($e) => [
                'id' => (string) $e->id(),
                'title' => (string) ($e->value('title') ?? ''),
                'slug' => (string) ($e->slug() ?? ''),
                'collection' => (string) ($e->collectionHandle() ?? ''),
                'published' => (bool) $e->published(),
            ])
            ->values()
            ->all();
    }

    /**
     * Entries whose field text contains the query, with a snippet.
     *
     * @see EntryContentSearch::search()
     */
    public function searchEntryContent(?string $collectionHandle, string $query, int $limit): array
    {
        return (new EntryContentSearch)->search($collectionHandle, $query, $limit);
    }
}

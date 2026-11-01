<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Support\SearchText;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Field;

/**
 * Finds entries by what their fields say rather than their title: flattens
 * each entry's text and returns the matches with a snippet.
 */
final class EntryContentSearch
{
    /** Max entries whose body content is flattened + scanned per searchEntryContent() call. */
    private const SEARCH_CONTENT_SCAN_LIMIT = 800;

    /** Per-entry cap on flattened content length (chars) before matching, to bound work. */
    private const SEARCH_CONTENT_FLATTEN_CHARS = 20000;

    /** Structural array keys skipped when flattening entry content (Bard/replicator plumbing). */
    private const CONTENT_SKIP_KEYS = ['type', 'id', '_id', 'enabled', 'collapsed'];

    /**
     * Deep search: find entries whose *body content* (not just title/slug) matches
     * the query. Each entry's field values are flattened to plain text server-side
     * and matched with the same tolerant tokenising as findEntriesShortlist(), so a
     * phrase split across rich-text nodes (e.g. "Kursleitung:" + "Claudia Eva Reinig")
     * still resolves. The full content never leaves the server — callers receive only
     * id/title/slug/collection plus a short snippet around the first match.
     *
     * @return array<int, array{id: string, title: string, slug: string, collection: string, snippet: string}>
     */
    public function search(?string $collectionHandle, string $query, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $tokens = SearchText::tokens($query);
        if ($tokens === []) {
            return [];
        }

        $siteHandle = Site::selected()?->handle() ?? Site::default()->handle();

        $q = Entry::query()->where('site', $siteHandle);
        if (is_string($collectionHandle) && $collectionHandle !== '') {
            $q->where('collection', $collectionHandle);
        }

        $candidates = $q->orderBy('updated_at', 'desc')->limit(self::SEARCH_CONTENT_SCAN_LIMIT + 1)->get();
        if ($candidates->count() > self::SEARCH_CONTENT_SCAN_LIMIT) {
            Log::warning('[entry-gen-tool] search_entry_content hit scan cap; some entries were not scanned', [
                'collection' => $collectionHandle,
                'scan_limit' => self::SEARCH_CONTENT_SCAN_LIMIT,
            ]);
            $candidates = $candidates->take(self::SEARCH_CONTENT_SCAN_LIMIT);
        }

        $tokensFold = array_values(array_filter(array_map(fn ($t) => SearchText::fold($t), $tokens)));

        $matches = [];
        foreach ($candidates as $entry) {
            $flat = $this->flattenEntryText($entry);
            if ($flat === '') {
                continue;
            }
            $haystack = SearchText::fold($flat);

            $hits = 0;
            foreach ($tokensFold as $token) {
                if (str_contains($haystack, $token)) {
                    $hits++;
                }
            }

            // Require every token to appear somewhere in the content (precision).
            if ($tokensFold === [] || $hits < count($tokensFold)) {
                continue;
            }

            $matches[] = [
                'entry' => $entry,
                'score' => $hits,
                'snippet' => $this->buildContentSnippet($flat, $tokens),
            ];
        }

        // Most token hits first; PHP 8 sort is stable so ties keep recency order.
        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($m) => [
            'id' => (string) $m['entry']->id(),
            'title' => (string) ($m['entry']->value('title') ?? ''),
            'slug' => (string) ($m['entry']->slug() ?? ''),
            'collection' => (string) ($m['entry']->collectionHandle() ?? ''),
            'snippet' => $m['snippet'],
        ], array_slice($matches, 0, $limit));
    }

    /**
     * Flatten all string leaves of an entry's raw data into one plain-text blob,
     * skipping structural keys (Bard/replicator plumbing) and capping total length.
     */
    private function flattenEntryText(StatamicEntry $entry): string
    {
        $parts = [];
        $this->collectStringLeaves($entry->data()->all(), $parts, 0);
        $text = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');

        return mb_strlen($text) > self::SEARCH_CONTENT_FLATTEN_CHARS
            ? mb_substr($text, 0, self::SEARCH_CONTENT_FLATTEN_CHARS)
            : $text;
    }

    /**
     * Recursively gather non-empty string values from nested entry data.
     *
     * @param  mixed  $value
     * @param  array<int, string>  $out
     */
    private function collectStringLeaves($value, array &$out, int $depth): void
    {
        if ($depth > 12 || count($out) > 5000) {
            return;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key) && in_array($key, self::CONTENT_SKIP_KEYS, true)) {
                    continue;
                }
                $this->collectStringLeaves($child, $out, $depth + 1);
            }
        }
    }

    /**
     * A short context window around the first query token found in the flat text.
     *
     * @param  array<int, string>  $tokens  Lower-cased, diacritics-preserved query tokens.
     */
    private function buildContentSnippet(string $flat, array $tokens): string
    {
        $pos = false;
        foreach ($tokens as $token) {
            $found = mb_stripos($flat, $token);
            if ($found !== false) {
                $pos = $found;
                break;
            }
        }

        if ($pos === false) {
            return Str::limit($flat, 160);
        }

        $start = max(0, $pos - 40);
        $snippet = trim(mb_substr($flat, $start, 200));

        return ($start > 0 ? '…' : '').$snippet.'…';
    }
}

<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use MotionWork\StatamicAiAssistant\Semantic\SemanticSearch;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Entries similar in meaning to a find_entries query, across every site's
 * index, for the slots the keyword matches left free. Each entry passes the
 * same `view` check as a keyword match. Empty whenever semantic search cannot
 * run (SemanticSearch::nearest() returns null), so find_entries is then
 * exactly the keyword search.
 */
final class SemanticEntryMatches
{
    public function __construct(
        private readonly SemanticSearch $semantic,
        private readonly ConnectorContext $context,
    ) {}

    /**
     * @param  list<string>  $exclude  Entry ids already in the result.
     * @return list<array<string, mixed>> Entry summaries, best match first.
     */
    public function find(string $query, ?string $collection, array $exclude, int $limit): array
    {
        if ($limit < 1 || trim($query) === '') {
            return [];
        }

        $matches = [];
        foreach ($this->rankedIds($query, $collection, $limit + count($exclude)) as $id) {
            if (in_array($id, $exclude, true)) {
                continue;
            }
            $entry = Entry::find($id);
            if ($entry === null || ($this->context->actor() !== null && ! $this->context->actor()->can('view', $entry))) {
                continue;
            }
            $matches[] = $this->context->entrySummary($entry);
            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * Ids from all sites' indexes, highest similarity first.
     *
     * @return list<string>
     */
    private function rankedIds(string $query, ?string $collection, int $perSite): array
    {
        $scores = [];
        foreach (Site::all() as $site) {
            foreach ($this->semantic->nearest($site->handle(), $query, $collection, $perSite) ?? [] as $hit) {
                $scores[$hit['id']] = $hit['score'];
            }
        }
        arsort($scores);

        return array_map('strval', array_keys($scores));
    }
}

<?php

namespace MotionWork\StatamicAiAssistant\Semantic;

/**
 * Merges the keyword ranking with the semantic one (reciprocal rank fusion):
 * an entry high in either list ranks high, one found by both ranks highest.
 * Pinned ids (exact or full-phrase title/slug matches) always come first, in
 * keyword order, so an entry the user named exactly is never outranked.
 */
final class SearchFusion
{
    /** RRF damping constant (the usual 60). */
    private const K = 60;

    /**
     * @param  list<string>  $keywordIds  Best first.
     * @param  list<string>  $semanticIds  Best first.
     * @param  list<string>  $pinnedIds
     * @return list<string>
     */
    public static function merge(array $keywordIds, array $semanticIds, array $pinnedIds = []): array
    {
        $scores = [];
        foreach ([$keywordIds, $semanticIds] as $ranking) {
            foreach (array_values(array_unique($ranking)) as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + 1 / (self::K + $rank + 1);
            }
        }

        $pinned = array_values(array_intersect($keywordIds, $pinnedIds));
        $rest = array_diff_key($scores, array_flip($pinned));
        arsort($rest); // stable: ties keep keyword-then-semantic order

        return array_values(array_unique([...$pinned, ...array_map('strval', array_keys($rest))]));
    }
}

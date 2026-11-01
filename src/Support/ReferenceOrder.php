<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Entry;

/**
 * Orders a translation selection so referenced entries are translated first.
 */
final class ReferenceOrder
{
    /**
     * Topologically order the selection so entries whose ids appear inside
     * OTHER selected entries' data come first (referenced before referencing).
     * Detection is a cheap substring scan of the raw data — ids are UUIDs, so
     * false positives are practically impossible. Cycles (A ↔ B) fall back to
     * the original selection order for the entries involved.
     *
     * @param  Collection<int, Entry>  $entries
     * @return Collection<int, Entry>
     */
    public static function sort(Collection $entries): Collection
    {
        if ($entries->count() < 2) {
            return $entries;
        }

        $ids = $entries->map(fn ($e) => (string) $e->id())->all();

        $payloads = [];
        foreach ($entries as $entry) {
            $payloads[(string) $entry->id()] = json_encode($entry->data()->toArray()) ?: '';
        }

        // id => selected ids it references (its dependencies).
        $deps = [];
        foreach ($payloads as $id => $json) {
            foreach ($ids as $other) {
                if ($other !== $id && str_contains($json, $other)) {
                    $deps[$id][] = $other;
                }
            }
        }

        $ordered = [];
        $emitted = [];
        $remaining = $ids;

        while ($remaining !== []) {
            $next = [];
            $progress = false;

            foreach ($remaining as $id) {
                $unmet = array_filter($deps[$id] ?? [], fn ($d) => ! isset($emitted[$d]));

                if ($unmet === []) {
                    $ordered[] = $id;
                    $emitted[$id] = true;
                    $progress = true;
                } else {
                    $next[] = $id;
                }
            }

            if (! $progress) {
                // Reference cycle — emit the rest in selection order.
                array_push($ordered, ...$next);
                break;
            }

            $remaining = $next;
        }

        $byId = $entries->keyBy(fn ($e) => (string) $e->id());

        return collect($ordered)->map(fn ($id) => $byId[$id])->values();
    }
}

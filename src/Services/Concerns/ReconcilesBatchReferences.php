<?php

namespace MotionWork\StatamicAiAssistant\Services\Concerns;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * The pass after a batch that points references at the localizations the
 * batch created. Uses the host's entry translator in remap-only mode.
 */
trait ReconcilesBatchReferences
{
    /**
     * Post-batch reference reconcile: re-run the reference pass in remap-only
     * mode over every localization the batch produced (plus linked entries it
     * auto-created). Heals references that were left pointing at source-site
     * entries because the linked entry had no translation yet when the
     * referencing page was translated — after the batch, those translations
     * exist, so the ids can be swapped. Never creates entries, never calls
     * DeepL. Returns the number of entries whose references were healed.
     *
     * @param  array<int, array{0: string, 1: string}>  $entryLocalePairs  [entryId, destinationLocale]
     * @param  array<int, string>  $extraTargetEntryIds  Localization ids to reconcile directly (e.g. auto-created linked entries)
     */
    public function reconcileBatchReferences(array $entryLocalePairs, array $extraTargetEntryIds = []): int
    {
        $targets = [];

        foreach ($entryLocalePairs as $pair) {
            if (! is_array($pair) || count($pair) !== 2) {
                continue;
            }
            [$entryId, $locale] = $pair;

            $site = Site::all()->firstWhere('locale', $locale);
            $entry = Entry::find($entryId);

            // in() walks the localization graph from whichever localization the
            // caller selected, so this finds the batch's target entry directly.
            $target = ($site && $entry) ? $entry->in($site->handle()) : null;

            if ($target) {
                $targets[(string) $target->id()] = $target;
            }
        }

        foreach ($extraTargetEntryIds as $id) {
            if (is_string($id) && $id !== '' && ! isset($targets[$id]) && ($entry = Entry::find($id))) {
                $targets[$id] = $entry;
            }
        }

        $healed = 0;

        foreach ($targets as $target) {
            try {
                if ($this->entryTranslator->remapEntryReferences($target)) {
                    $healed++;
                }
            } catch (\Throwable $e) {
                Log::warning('TranslationService: reference reconcile failed for entry', [
                    'entry_id' => (string) $target->id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Drain the remap-only pass's bookkeeping — still-unresolved ids were
        // already reported per entry during translation.
        $this->entryTranslator->takeUnresolvedReferences();

        return $healed;
    }
}

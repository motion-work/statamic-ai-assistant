<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Services\Concerns\ChecksOverwriteConflicts;
use MotionWork\StatamicAiAssistant\Services\Concerns\ReconcilesBatchReferences;
use MotionWork\StatamicAiAssistant\Services\Concerns\ReportsTranslationCoverage;
use MotionWork\StatamicAiAssistant\Services\Concerns\TracksTranslationBatchProgress;
use MotionWork\StatamicAiAssistant\Services\Concerns\TranslatesTerms;
use MotionWork\StatamicAiAssistant\Support\CollectionSites;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;

class TranslationService
{
    use ChecksOverwriteConflicts;
    use ReconcilesBatchReferences;
    use ReportsTranslationCoverage;
    use TracksTranslationBatchProgress;
    use TranslatesTerms;

    protected EntryTranslator $entryTranslator;

    protected TermTranslator $termTranslator;

    public function __construct(EntryTranslator $entryTranslator, ?TermTranslator $termTranslator = null)
    {
        $this->entryTranslator = $entryTranslator;
        $this->termTranslator = $termTranslator ?? app(TermTranslator::class);
    }

    /**
     * Translate a single entry from source locale to destination locale using DeepL.
     *
     * @return array{success: bool, entry_id: string, title: string, origin_title: string, target_title: ?string, is_new: bool, skipped: bool, error: string|null, edit_url: ?string, destination_locale: string}
     */
    public function translateEntry(
        Entry $entry,
        string $sourceLocale,
        string $destinationLocale,
        bool $overwrite = true,
        int $maxDepth = 1,
    ): array {
        $sourceSite = Site::all()->firstWhere('locale', $sourceLocale);
        $destinationSite = Site::all()->firstWhere('locale', $destinationLocale);

        if (! $sourceSite || ! $destinationSite) {
            return $this->errorResult($entry, __('Source or destination site not found.'), $destinationLocale);
        }

        if (! CollectionSites::includes($entry, $destinationSite->handle())) {
            return $this->errorResult($entry, CollectionSites::refusal($entry, $destinationSite->handle()), $destinationLocale);
        }

        $sourceEntry = $this->resolveSourceEntry($entry, $sourceSite);
        if (! $sourceEntry) {
            return $this->errorResult($entry, __('Source language entry does not exist for entry :title', [
                'title' => EntryLabel::for($entry),
            ]), $destinationLocale);
        }

        $originTitle = EntryLabel::for($sourceEntry);

        $existingTarget = null;
        if ($sourceEntry->existsIn($destinationSite->handle())) {
            $existingTarget = $sourceEntry->in($destinationSite->handle());

            if (! $overwrite) {
                return $this->skippedResult($existingTarget, $sourceEntry, $destinationLocale);
            }
        }

        try {
            $targetEntry = $this->entryTranslator->translateEntry(
                $sourceEntry,
                $destinationSite->handle(),
                $existingTarget,
                0,
                $maxDepth,
            );

            return $this->translatedResult($targetEntry, $sourceEntry, $sourceLocale, $destinationLocale, $existingTarget === null);
        } catch (\Exception $e) {
            $this->entryTranslator->resetLinkedEntriesBuffer();

            Log::error('TranslationService: DeepL translation failed', [
                'entry_id' => $sourceEntry->id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResult($sourceEntry, __('Translation failed for entry :title: :error', [
                'title' => $originTitle,
                'error' => $e->getMessage(),
            ]), $destinationLocale);
        }
    }

    /**
     * The localization exists and may not be overwritten.
     *
     * @return array<string, mixed>
     */
    private function skippedResult(Entry $existingTarget, Entry $sourceEntry, string $destinationLocale): array
    {
        return [
            'success' => true,
            'entry_id' => $existingTarget->id(),
            'source_entry_id' => $sourceEntry->id(),
            'title' => EntryLabel::for($sourceEntry),
            'origin_title' => EntryLabel::for($sourceEntry),
            'target_title' => EntryLabel::for($existingTarget),
            'is_new' => false,
            'skipped' => true,
            'error' => null,
            'edit_url' => $existingTarget->editUrl(),
            'destination_locale' => $destinationLocale,
            'linked_entries' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function translatedResult(Entry $targetEntry, Entry $sourceEntry, string $sourceLocale, string $destinationLocale, bool $isNew): array
    {
        $linkedEntries = $this->entryTranslator->takeLinkedEntriesCreated();
        $primaryId = $targetEntry->id();
        $linkedEntries = array_values(array_filter(
            $linkedEntries,
            fn (array $e) => ($e['entry_id'] ?? '') !== $primaryId
        ));

        // References left pointing at source-site entries (linked entry not
        // translated yet / depth budget exhausted). The post-batch reconcile
        // pass heals the ones translated later in the same batch; the rest
        // stay visible as a warning so editors know to translate them.
        $unresolved = $this->entryTranslator->takeUnresolvedReferences();

        // Text-bearing fields skipped due to localizable: false — shown as a
        // warning so "still in the source language" is never a mystery.
        $untranslatedFields = $this->entryTranslator->takeSkippedNonLocalizable();

        Log::info('TranslationService: Entry translated via DeepL', [
            'entry_id' => $targetEntry->id(),
            'source' => $sourceLocale,
            'destination' => $destinationLocale,
            'is_new' => $isNew,
        ]);

        return [
            'success' => true,
            'entry_id' => $targetEntry->id(),
            'source_entry_id' => $sourceEntry->id(),
            'edit_url' => $targetEntry->editUrl(),
            'title' => EntryLabel::for($sourceEntry),
            'origin_title' => EntryLabel::for($sourceEntry),
            'target_title' => EntryLabel::for($targetEntry),
            'is_new' => $isNew,
            'skipped' => false,
            'error' => null,
            'destination_locale' => $destinationLocale,
            'linked_entries' => $linkedEntries,
            'unresolved_references' => $unresolved,
            'untranslated_fields' => $untranslatedFields,
        ];
    }

    /**
     * Every (entry, destination) pair to translate, skipping the source locale.
     *
     * @param  array<int, string>  $destinationLocales
     * @return array<int, array{0: Entry, 1: string}>
     */
    private function translationPairs(array $entries, string $sourceLocale, array $destinationLocales): array
    {
        $pairs = [];

        foreach ($entries as $entry) {
            foreach (array_unique(array_filter($destinationLocales)) as $destinationLocale) {
                if ($destinationLocale !== $sourceLocale) {
                    $pairs[] = [$entry, $destinationLocale];
                }
            }
        }

        return $pairs;
    }

    /**
     * Translate multiple entries to one or more destination locales.
     *
     * @param  array<int, string>  $destinationLocales
     * @return array{translated: int, updated: int, skipped: int, errors: array, total: int, results: array}
     */
    public function translateBatch(
        array $entries,
        string $sourceLocale,
        array $destinationLocales,
        bool $overwrite = true,
        ?string $batchId = null,
        int $maxDepth = 1,
    ): array {
        $translated = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $results = [];

        $pairs = $this->translationPairs($entries, $sourceLocale, $destinationLocales);
        $total = count($pairs);

        foreach ($pairs as $index => [$entry, $destinationLocale]) {
            if ($batchId) {
                // 1-based step so the bar moves off 0% while the first item is translating.
                $this->updateBatchProgress($batchId, $index + 1, $total, $entry, $destinationLocale);
            }

            $result = $this->translateEntry($entry, $sourceLocale, $destinationLocale, $overwrite, $maxDepth);
            $results[] = $result;

            if (! $result['success']) {
                $errors[] = $result['error'];
            } elseif ($result['skipped'] ?? false) {
                $skipped++;
            } elseif ($result['is_new']) {
                $translated++;
            } else {
                $updated++;
            }

            if ($batchId) {
                $this->updateEntryStatus(
                    $batchId,
                    $entry->id(),
                    $destinationLocale,
                    $result['success'] ? 'completed' : 'failed',
                    $result['success'] ? null : $result['error'],
                    $this->entryStatusPayload($result)
                );
            }
        }

        if ($batchId) {
            $this->finalizeBatchProgress($batchId, $translated + $skipped, $updated, 0, $errors, $total);
        }

        $linkedCreatedTotal = array_sum(array_map(fn (array $r) => count($r['linked_entries'] ?? []), $results));

        return [
            'translated' => $translated + $skipped,
            'updated' => $updated,
            'skipped' => 0,
            'errors' => $errors,
            'total' => $total,
            'results' => $results,
            'linked_created_total' => $linkedCreatedTotal,
        ];
    }

    protected function resolveSourceEntry(Entry $entry, $sourceSite): ?Entry
    {
        if ($entry->site()->handle() === $sourceSite->handle()) {
            return $entry;
        }

        if ($entry->existsIn($sourceSite->handle())) {
            return $entry->in($sourceSite->handle());
        }

        return null;
    }

    protected function errorResult(Entry $entry, string $error, ?string $destinationLocale = null): array
    {
        $title = EntryLabel::for($entry);

        return [
            'success' => false,
            'entry_id' => $entry->id(),
            'source_entry_id' => $entry->id(),
            'title' => $title,
            'origin_title' => $title,
            'target_title' => null,
            'is_new' => false,
            'skipped' => false,
            'error' => $error,
            'edit_url' => null,
            'destination_locale' => $destinationLocale ?? '',
            'linked_entries' => [],
        ];
    }
}

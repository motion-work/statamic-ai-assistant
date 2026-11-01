<?php

namespace MotionWork\StatamicAiAssistant\Services\Concerns;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

/**
 * Bulk translation of taxonomy terms. Terms localize in place, so this needs
 * only the term translator and reports results in the entry result shape.
 */
trait TranslatesTerms
{
    /**
     * Translate a single taxonomy term into a destination locale. Terms
     * localize in place (same term id, per-site data), so there is no
     * origin-graph or linked-entry handling — but the result mirrors
     * translateEntry()'s shape so the CP progress UI renders it identically.
     *
     * @return array<string, mixed>
     */
    public function translateTerm(string $termId, string $sourceLocale, string $destinationLocale, bool $overwrite = true): array
    {
        $sourceSite = Site::all()->firstWhere('locale', $sourceLocale);
        $destinationSite = Site::all()->firstWhere('locale', $destinationLocale);

        if (! $sourceSite || ! $destinationSite) {
            return $this->termErrorResult($termId, __('Source or destination site not found.'), $destinationLocale);
        }

        $term = Term::find($termId);
        if ($term && method_exists($term, 'term')) {
            $term = $term->term();
        }

        if (! $term) {
            return $this->termErrorResult($termId, __('Term not found: :id', ['id' => $termId]), $destinationLocale);
        }

        $originTitle = (string) ($term->in($sourceSite->handle())->title() ?? $term->slug());

        $existingData = $term->dataForLocale($destinationSite->handle());
        $exists = collect($existingData)->isNotEmpty();

        if ($exists && ! $overwrite) {
            return [
                'success' => true,
                'entry_id' => $termId,
                'source_entry_id' => $termId,
                'title' => $originTitle,
                'origin_title' => $originTitle,
                'target_title' => (string) ($term->in($destinationSite->handle())->title() ?? $originTitle),
                'is_new' => false,
                'skipped' => true,
                'error' => null,
                'edit_url' => $term->in($destinationSite->handle())->editUrl(),
                'destination_locale' => $destinationLocale,
                'linked_entries' => [],
            ];
        }

        try {
            $localized = $this->termTranslator->translateTerm($term, $sourceSite->handle(), $destinationSite->handle());
            $untranslatedFields = $this->termTranslator->takeSkippedNonLocalizable();

            Log::info('TranslationService: Term translated via DeepL', [
                'term_id' => $termId,
                'source' => $sourceLocale,
                'destination' => $destinationLocale,
                'is_new' => ! $exists,
            ]);

            return [
                'success' => true,
                'entry_id' => $termId,
                'source_entry_id' => $termId,
                'edit_url' => $localized->editUrl(),
                'title' => $originTitle,
                'origin_title' => $originTitle,
                'target_title' => (string) ($localized->title() ?? $originTitle),
                'is_new' => ! $exists,
                'skipped' => false,
                'error' => null,
                'destination_locale' => $destinationLocale,
                'linked_entries' => [],
                'unresolved_references' => [],
                'untranslated_fields' => $untranslatedFields,
            ];
        } catch (\Exception $e) {
            Log::error('TranslationService: DeepL term translation failed', [
                'term_id' => $termId,
                'error' => $e->getMessage(),
            ]);

            return $this->termErrorResult($termId, __('Translation failed for term :title: :error', [
                'title' => $originTitle,
                'error' => $e->getMessage(),
            ]), $destinationLocale);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function termErrorResult(string $termId, string $error, string $destinationLocale): array
    {
        return [
            'success' => false,
            'entry_id' => $termId,
            'source_entry_id' => $termId,
            'title' => $termId,
            'origin_title' => $termId,
            'target_title' => null,
            'is_new' => false,
            'skipped' => false,
            'error' => $error,
            'edit_url' => null,
            'destination_locale' => $destinationLocale,
            'linked_entries' => [],
        ];
    }
}

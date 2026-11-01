<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MotionWork\StatamicAiAssistant\Services\DeeplService;
use MotionWork\StatamicAiAssistant\Services\TranslationService;
use MotionWork\StatamicAiAssistant\Support\TrimAiOutput;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;

class TranslationController
{
    public function __construct(protected TranslationService $translationService) {}

    /**
     * Translate a single entry synchronously.
     */
    public function translateEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entry_id' => 'required|string',
            'destination_locale' => 'required|string',
            'overwrite' => 'boolean',
        ]);

        $entry = Entry::find($data['entry_id']);
        if (! $entry) {
            return response()->json(['error' => __('Entry not found.')], 404);
        }

        $sourceLocale = Site::default()->locale();

        $maxDepth = (int) config('statamic-ai-assistant.linked_entries_max_depth', 1);

        $result = $this->translationService->translateEntry(
            $entry,
            $sourceLocale,
            $data['destination_locale'],
            $data['overwrite'] ?? true,
            $maxDepth,
        );

        return response()->json($result);
    }

    /**
     * Preflight check for the Translate (DeepL) entry action — same rule as Bulk translations (conflict without overwrite).
     */
    public function conflictCheck(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entry_ids' => 'required|array|min:1',
            'entry_ids.*' => 'string',
            'destination_locales' => 'required|array|min:1',
            'destination_locales.*' => 'string',
        ]);

        $entries = collect($data['entry_ids'])
            ->map(fn ($id) => Entry::find($id))
            ->filter();

        if ($entries->isEmpty()) {
            return response()->json([
                'has_conflict' => false,
                'conflicting_locales' => [],
                'conflicting_locale_labels' => [],
                'conflicts_by_locale' => [],
            ]);
        }

        foreach ($entries as $entry) {
            abort_unless(User::current()->can('edit', $entry), 403);
        }

        $destinationLocales = array_values(array_unique(array_filter($data['destination_locales'])));
        $sourceLocale = Site::default()->locale();

        $conflictsByLocale = $this->translationService->conflictDetailsWithoutOverwrite(
            $entries->all(),
            $sourceLocale,
            $destinationLocales,
        );

        $conflictingLocales = array_column($conflictsByLocale, 'locale');
        $conflictingLocaleLabels = array_column($conflictsByLocale, 'locale_label');

        return response()->json([
            'has_conflict' => count($conflictsByLocale) > 0,
            'conflicting_locales' => $conflictingLocales,
            'conflicting_locale_labels' => $conflictingLocaleLabels,
            'conflicts_by_locale' => $conflictsByLocale,
        ]);
    }

    /**
     * Translate a single field value via DeepL (for per-field translate buttons).
     */
    public function translateField(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => 'required_without:texts|string',
            /** Several texts in one call: the text runs between Bard sets, so sets are never sent to the translator. */
            'texts' => 'required_without:text|array|min:1|max:200',
            'texts.*' => 'nullable|string',
            'source_locale' => 'nullable|string',
            'target_locale' => 'required|string',
            /** When true, text is HTML (e.g. Bard) and DeepL preserves structure via tag_handling=html. Plain text must omit this or send false — otherwise apostrophes become entities (e.g. &#x27;). */
            'html' => 'sometimes|boolean',
        ]);

        $sourceLocale = isset($data['source_locale']) && trim($data['source_locale']) !== ''
            ? $data['source_locale']
            : null;

        $deeplOptions = ($data['html'] ?? false) ? ['tag_handling' => 'html'] : [];

        try {
            /** @var DeeplService $deepl */
            $deepl = app(DeeplService::class);

            if (isset($data['texts'])) {
                $translations = $deepl->translateBatch(
                    array_map(fn ($t) => (string) $t, array_values($data['texts'])),
                    $sourceLocale,
                    $data['target_locale'],
                    $deeplOptions,
                );

                return response()->json([
                    'translations' => array_map(fn ($t) => TrimAiOutput::normalize($t), $translations),
                    'notice' => $deepl->llmFallbackNotice(),
                ]);
            }

            $translated = $deepl->translateText(
                $data['text'],
                $sourceLocale,
                $data['target_locale'],
                $deeplOptions,
            );

            return response()->json([
                'translated' => TrimAiOutput::normalize($translated),
                'notice' => $deepl->llmFallbackNotice(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => __('Translation failed: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * DeepL API usage for the current billing period (characters / documents).
     */
    public function deeplUsage(): JsonResponse
    {
        if (! config('statamic-ai-assistant.translations', true)) {
            return response()->json(['enabled' => false]);
        }

        try {
            /** @var DeeplService $deepl */
            $deepl = app(DeeplService::class);

            if ($deepl->usesLlmFallback()) {
                return response()->json([
                    'enabled' => true,
                    'engine' => 'llm',
                    'notice' => $deepl->llmFallbackNotice(),
                ]);
            }

            return response()->json(array_merge(
                ['enabled' => true],
                $deepl->getUsageForApi()
            ));
        } catch (\Throwable $e) {
            return response()->json([
                'enabled' => true,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get progress for an async batch.
     */
    public function progress(string $batchId): JsonResponse
    {
        $progress = $this->translationService->getBatchProgress($batchId);

        if (! $progress) {
            return response()->json(['error' => __('Batch not found.')], 404);
        }

        return response()->json($progress);
    }

    /**
     * Return translation coverage statistics across all multisite collections.
     */
    public function status(): JsonResponse
    {
        $status = $this->translationService->getTranslationStatus();
        $navigations = $this->translationService->getNavigationsList();

        // Multi-site taxonomies for the bulk tool's taxonomy mode picker.
        $taxonomies = Taxonomy::all()
            ->filter(fn ($taxonomy) => $taxonomy->sites() && $taxonomy->sites()->count() > 1)
            ->map(fn ($taxonomy) => ['handle' => $taxonomy->handle(), 'title' => $taxonomy->title()])
            ->values()
            ->all();

        return response()->json([
            'collections' => $status,
            'navigations' => $navigations,
            'taxonomies' => $taxonomies,
        ]);
    }
}

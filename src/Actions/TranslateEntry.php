<?php

namespace BoldWeb\StatamicAiAssistant\Actions;

use BoldWeb\StatamicAiAssistant\Services\CpTranslationBatchRunner;
use BoldWeb\StatamicAiAssistant\Services\TranslationService;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;

class TranslateEntry extends Action
{
    public static function title()
    {
        return __('Translate (DeepL)');
    }

    protected static $separator = true;

    public function visibleTo($item)
    {
        if (! config('statamic-ai-assistant.translations', true)) {
            return false;
        }

        return $item instanceof Entry && $this->isMultisite();
    }

    public function authorize($user, $item)
    {
        return $user->can('edit', $item);
    }

    protected function fieldItems($items = [])
    {
        if (! $this->isMultisite()) {
            return [];
        }

        $sites = Site::all();
        $itemsCollection = null;

        if (! empty($items)) {
            $itemsCollection = is_array($items) ? collect($items) : $items;
        } elseif (property_exists($this, 'items') && ! empty($this->items)) {
            $itemsCollection = is_array($this->items) ? collect($this->items) : $this->items;
        }

        $sourceLocale = Site::default()->locale();

        $targetSites = $sites
            ->filter(fn ($site) => $site->locale() !== $sourceLocale)
            ->values()
            ->map(fn ($site) => [
                'handle' => $site->handle(),
                'locale' => $site->locale(),
                'name' => $site->name(),
            ])
            ->all();

        $defaultDestinationLocales = array_column($targetSites, 'locale');

        $entryIdsForPreflight = [];
        if ($itemsCollection && $itemsCollection->count() > 0) {
            $entryIdsForPreflight = $itemsCollection
                ->filter(fn ($e) => $e instanceof Entry)
                ->map(fn ($e) => $e->id())
                ->values()
                ->all();
        }

        $siteLocaleLabels = $sites->mapWithKeys(function ($site) {
            return [$site->locale() => $site->name().' ('.$site->locale().')'];
        })->all();

        $preflightConfig = [
            'type' => 'translation_action_preflight',
            'display' => ' ',
            'hide_display' => true,
            'entry_ids' => $entryIdsForPreflight,
            'site_locale_labels' => $siteLocaleLabels,
            'default_source_locale' => $sourceLocale,
        ];

        return [
            'destination_locales' => [
                'type' => 'translation_target_languages',
                'display' => __('Target languages'),
                'instructions' => __('Target languages help'),
                'sites' => $targetSites,
                'default' => $defaultDestinationLocales,
                'validate' => 'required|array|min:1',
            ],
            'overwrite' => [
                'type' => 'toggle',
                'display' => __('Overwrite existing translations'),
                'default' => false,
            ],
            'translation_preflight_hints' => array_merge($preflightConfig, [
                'preflight_part' => 'hints',
            ]),
            'translation_preflight_footer' => array_merge($preflightConfig, [
                'preflight_part' => 'footer',
            ]),
        ];
    }

    public function run($items, $values)
    {
        if (! $this->isMultisite()) {
            return [
                'callback' => ['errorCallback', __('This action is not available for single site installations.')],
            ];
        }

        $sourceLocale = Site::default()->locale();

        $rawDest = $values['destination_locales'] ?? [];
        if (! is_array($rawDest)) {
            $rawDest = $rawDest !== null && $rawDest !== '' ? [$rawDest] : [];
        }
        if (empty($rawDest) && isset($values['destination_language'])) {
            $rawDest = [$values['destination_language']];
        }

        $destinationLocales = CpTranslationBatchRunner::normalizeDestinationLocales($rawDest, null);

        $overwrite = (bool) ($values['overwrite'] ?? false);
        $maxDepth = (int) config('statamic-ai-assistant.linked_entries_max_depth', 1);

        if ($destinationLocales === []) {
            return ['callback' => ['errorCallback', __('Select at least one destination language.')]];
        }

        foreach ($destinationLocales as $dl) {
            if ($dl === $sourceLocale) {
                return ['callback' => ['errorCallback', __('Source and destination languages cannot be the same.')]];
            }
        }

        $itemsCollection = is_array($items) ? collect($items) : $items;
        $entries = $itemsCollection->filter(fn ($item) => $item instanceof Entry)->values()->all();

        if (empty($entries)) {
            return ['callback' => ['errorCallback', __('No valid entries selected.')]];
        }

        /** @var TranslationService $translationService */
        $translationService = app(TranslationService::class);

        if (! $overwrite && $translationService->hasConflictWithoutOverwrite($entries, $sourceLocale, $destinationLocales)) {
            return ['callback' => ['errorCallback', __('Conflict without overwrite message')]];
        }

        /** @var CpTranslationBatchRunner $runner */
        $runner = app(CpTranslationBatchRunner::class);

        try {
            $result = $runner->run($entries, $sourceLocale, $destinationLocales, $overwrite, $maxDepth);
        } catch (\InvalidArgumentException $e) {
            return ['callback' => ['errorCallback', $e->getMessage()]];
        } catch (\RuntimeException $e) {
            return ['callback' => ['errorCallback', $e->getMessage()]];
        }

        if ($result['mode'] === 'async') {
            $message = __('Translation queued message', ['count' => $result['total']]);

            return [
                'callback' => [
                    'successCallback',
                    route('statamic.cp.statamic-ai-assistant.translations'),
                    $message,
                ],
            ];
        }

        $message = $this->buildSyncSummaryMessage($result);
        $editUrl = $this->resolveSuccessEditUrl($entries, $result);

        return [
            'callback' => ['successCallback', $editUrl, $message],
        ];
    }

    /**
     * @param  array<int, Entry>  $entries
     * @param  array<string, mixed>  $batchResult
     */
    protected function resolveSuccessEditUrl(array $entries, array $batchResult): string
    {
        $fallback = $entries[0]->editUrl();
        foreach ($batchResult['results'] ?? [] as $r) {
            if (($r['success'] ?? false) && ! ($r['skipped'] ?? false) && ! empty($r['edit_url'])) {
                return $r['edit_url'];
            }
        }
        foreach (array_reverse($batchResult['results'] ?? []) as $r) {
            if (($r['success'] ?? false) && ! empty($r['edit_url'])) {
                return $r['edit_url'];
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function buildSyncSummaryMessage(array $result): string
    {
        $parts = [];
        if ($result['translated'] > 0) {
            $parts[] = __('Translated :count entry(ies)', ['count' => $result['translated']]);
        }
        if ($result['updated'] > 0) {
            $parts[] = __('Overridden :count existing translation(s)', ['count' => $result['updated']]);
        }

        $linkedTotal = (int) ($result['linked_created_total'] ?? 0);
        if ($linkedTotal > 0) {
            $parts[] = __('Also created :count linked page(s)', ['count' => $linkedTotal]);
        }

        $message = ! empty($parts) ? implode(', ', $parts) : __('No entries processed');
        $message .= ' '.__('(out of :total total)', ['total' => $result['total']]);

        if (count($result['errors']) > 0) {
            $message .= ' '.__('(:error_count error(s))', ['error_count' => count($result['errors'])]);
        }

        return $message;
    }

    private function isMultisite()
    {
        return Site::all()->count() > 1;
    }
}

<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use MotionWork\StatamicAiAssistant\Support\CpFeatures;
use MotionWork\StatamicAiAssistant\Support\PlanEntryDecorator;
use MotionWork\StatamicAiAssistant\Support\SiteLanguageResolver;
use MotionWork\StatamicAiAssistant\Tools\ToolArguments;
use Statamic\Facades\Entry;
use Statamic\Sites\Site;

/**
 * localize_entry: queues a translation of an existing entry into another
 * site as a plan row, within the run's entry cap.
 */
final class LocalizeEntryJobTool
{
    public function __construct(
        private ?EntryGenerationBatchService $batch,
        private ?PlanEntryDecorator $decorator,
    ) {}

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{ok: bool, id?: string, count?: int, error?: string, message?: string, entry_id?: string, target_site?: string}
     */
    public function handleLocalizeEntryToolCall(string $argumentsJson, string $sessionId, array $catalog, int $cap, int $alreadyCreated, ?int $addCap = null): array
    {
        if ($this->batch === null || $this->decorator === null) {
            return ['ok' => false, 'error' => 'planner_dependencies_missing'];
        }

        if (! CpFeatures::translationsEnabled()) {
            return [
                'ok' => false,
                'error' => 'translations_disabled',
                'message' => 'Translations are disabled. Tell the user. Do not create a new entry on the current site as a substitute.',
            ];
        }

        if ($alreadyCreated >= $cap) {
            return ['ok' => false, 'error' => 'cap_reached', 'count' => $alreadyCreated];
        }

        $args = ToolArguments::decode($argumentsJson, 'localize_entry');
        if ($args === null) {
            return ToolArguments::invalid();
        }

        $entryId = isset($args['entry_id']) && is_string($args['entry_id']) ? trim($args['entry_id']) : '';
        $target = isset($args['target_site']) && is_string($args['target_site']) ? trim($args['target_site']) : '';
        $label = isset($args['label']) && is_string($args['label']) ? trim($args['label']) : '';

        if ($entryId === '' || $target === '') {
            return ['ok' => false, 'error' => 'missing_required_fields', 'message' => 'entry_id and target_site are required.'];
        }

        $entry = Entry::find($entryId);
        if (! $entry) {
            return ['ok' => false, 'error' => 'entry_not_found', 'entry_id' => $entryId, 'message' => 'No entry with that id. Use find_entries, then call localize_entry again.'];
        }

        $collectionHandle = (string) $entry->collectionHandle();
        $blueprintHandle = (string) ($entry->blueprint()?->handle() ?? '');
        $catalogHandles = array_map(fn ($r) => $r['handle'] ?? '', $catalog);
        if (! in_array($collectionHandle, $catalogHandles, true)) {
            return ['ok' => false, 'error' => 'entry_collection_not_in_catalog', 'collection' => $collectionHandle];
        }

        $site = $this->resolveLocalizeTarget($entry, $target);
        if (is_array($site)) {
            return $site;
        }

        $decorated = $this->localizePlanRow($entry, $site, $label, $collectionHandle, $blueprintHandle);

        $added = $this->batch->addPlannedEntry($sessionId, $decorated, $addCap ?? $cap);
        if (! $added) {
            return ['ok' => false, 'error' => 'cap_or_session_rejected', 'count' => $alreadyCreated];
        }

        GeneratePlannedEntryJob::dispatch($sessionId, (string) $decorated['id']);

        return [
            'ok' => true,
            'id' => (string) $decorated['id'],
            'entry_id' => $entryId,
            'target_site' => $site->handle(),
            'count' => $alreadyCreated + 1,
            'message' => 'Queued a localization of this entry on site '.$site->handle().'. The worker saves it there, linked to the source entry as its origin, and translates the source content into it.',
        ];
    }

    /**
     * The site to localize onto, or the refusal (unknown site, or the entry's own site).
     *
     * @return Site|array<string, mixed>
     */
    private function resolveLocalizeTarget(mixed $entry, string $target): mixed
    {
        $resolved = (new SiteLanguageResolver)->resolve($target);
        if (! isset($resolved['site'])) {
            return [
                'ok' => false,
                'error' => (string) ($resolved['error'] ?? 'unknown_site'),
                'message' => (string) ($resolved['message'] ?? 'Unknown target site.'),
            ];
        }

        $site = $resolved['site'];
        if ($site->handle() === (string) $entry->locale()) {
            return [
                'ok' => false,
                'error' => 'same_site',
                'message' => 'That entry is already on site '.$site->handle().'. localize_entry writes a localization on a different site. Do not create a new entry on the current site.',
            ];
        }

        return $site;
    }

    /**
     * The plan row for a localize job, labelled "<title> → <site>" unless the model named it.
     *
     * @return array<string, mixed>
     */
    private function localizePlanRow(mixed $entry, mixed $site, string $label, string $collectionHandle, string $blueprintHandle): array
    {
        $siteLabel = (new SiteLanguageResolver)->displayName($site);
        if ($label === '') {
            $originTitle = (string) ($entry->value('title') ?? $entry->slug() ?? (string) $entry->id());
            $label = trim($originTitle.' → '.$siteLabel);
        }

        $decorated = $this->decorator->decorateOne([
            'collection' => $collectionHandle,
            'blueprint' => $blueprintHandle,
            'prompt' => 'Localize into '.$site->handle().' and translate the source content there.',
            'label' => $label,
            'entry_id' => (string) $entry->id(),
        ]);
        $decorated['operation'] = 'localize';
        $decorated['target_site'] = $site->handle();
        $decorated['target_locale'] = (string) $site->locale();
        $decorated['target_site_label'] = $siteLabel;

        return $decorated;
    }
}

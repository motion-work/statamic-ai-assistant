<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use MotionWork\StatamicAiAssistant\Support\PlanEntryDecorator;
use MotionWork\StatamicAiAssistant\Tools\ToolArguments;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * create_entry_job and update_entry_job: each validated call
 * becomes a plan row in the batch session and a queued GeneratePlannedEntryJob,
 * within the run's entry cap.
 */
final class EntryJobTools
{
    public function __construct(
        private ?EntryGenerationBatchService $batch,
        private ?PlanEntryDecorator $decorator,
    ) {}

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{ok: bool, id?: string, count?: int, error?: string}
     */
    public function handleCreateEntryJobToolCall(string $argumentsJson, string $sessionId, array $catalog, int $cap, int $alreadyCreated, ?int $addCap = null): array
    {
        if ($this->batch === null || $this->decorator === null) {
            return ['ok' => false, 'error' => 'planner_dependencies_missing'];
        }

        if ($alreadyCreated >= $cap) {
            return ['ok' => false, 'error' => 'cap_reached', 'count' => $alreadyCreated];
        }

        $args = ToolArguments::decode($argumentsJson, 'create_entry_job');
        if ($args === null) {
            return ToolArguments::invalid();
        }

        $collection = isset($args['collection']) && is_string($args['collection']) ? trim($args['collection']) : '';
        $blueprint = isset($args['blueprint']) && is_string($args['blueprint']) ? trim($args['blueprint']) : '';
        $entryPrompt = isset($args['prompt']) && is_string($args['prompt']) ? trim($args['prompt']) : '';
        $label = isset($args['label']) && is_string($args['label']) ? trim($args['label']) : '';

        if ($collection === '' || $entryPrompt === '') {
            return ['ok' => false, 'error' => 'missing_required_fields'];
        }

        $validated = $this->validateAndCoerceTarget($catalog, $collection, $blueprint);
        if ($validated === null) {
            return ['ok' => false, 'error' => 'invalid_target', 'collection' => $collection, 'blueprint' => $blueprint];
        }

        if ($label === '') {
            $label = Str::limit(strip_tags($entryPrompt), 60);
        }

        $decorated = $this->decorator->decorateOne([
            'collection' => $validated['collection'],
            'blueprint' => $validated['blueprint'],
            'prompt' => $entryPrompt,
            'label' => $label,
        ]);

        $added = $this->batch->addPlannedEntry($sessionId, $decorated, $addCap ?? $cap);
        if (! $added) {
            return ['ok' => false, 'error' => 'cap_or_session_rejected', 'count' => $alreadyCreated];
        }

        GeneratePlannedEntryJob::dispatch($sessionId, (string) $decorated['id']);

        return [
            'ok' => true,
            'id' => (string) $decorated['id'],
            'count' => $alreadyCreated + 1,
        ];
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{ok: bool, id?: string, count?: int, error?: string, entry_id?: string, collection?: string}
     */
    public function handleUpdateEntryJobToolCall(string $argumentsJson, string $sessionId, array $catalog, int $cap, int $alreadyCreated, ?int $addCap = null): array
    {
        if ($this->batch === null || $this->decorator === null) {
            return ['ok' => false, 'error' => 'planner_dependencies_missing'];
        }

        if ($alreadyCreated >= $cap) {
            return ['ok' => false, 'error' => 'cap_reached', 'count' => $alreadyCreated];
        }

        $args = ToolArguments::decode($argumentsJson, 'update_entry_job');
        if ($args === null) {
            return ToolArguments::invalid();
        }

        $entryId = isset($args['entry_id']) && is_string($args['entry_id']) ? trim($args['entry_id']) : '';
        $entryPrompt = isset($args['prompt']) && is_string($args['prompt']) ? trim($args['prompt']) : '';
        $label = isset($args['label']) && is_string($args['label']) ? trim($args['label']) : '';

        if ($entryId === '' || $entryPrompt === '') {
            return ['ok' => false, 'error' => 'missing_required_fields'];
        }

        $entry = Entry::find($entryId);
        if (! $entry) {
            return ['ok' => false, 'error' => 'entry_not_found', 'entry_id' => $entryId];
        }

        $collectionHandle = (string) $entry->collectionHandle();
        $blueprintHandle = (string) ($entry->blueprint()?->handle() ?? '');

        // Sanity-check that the entry's collection still exists in the catalog
        // we showed the LLM. Blueprint we trust from the entry itself.
        $catalogHandles = array_map(fn ($r) => $r['handle'] ?? '', $catalog);
        if (! in_array($collectionHandle, $catalogHandles, true)) {
            return ['ok' => false, 'error' => 'entry_collection_not_in_catalog', 'collection' => $collectionHandle];
        }

        if ($label === '') {
            $label = (string) ($entry->value('title') ?? Str::limit(strip_tags($entryPrompt), 60));
        }

        $decorated = $this->decorator->decorateOne([
            'collection' => $collectionHandle,
            'blueprint' => $blueprintHandle,
            'prompt' => $entryPrompt,
            'label' => $label,
            'entry_id' => $entryId,
        ]);

        $added = $this->batch->addPlannedEntry($sessionId, $decorated, $addCap ?? $cap);
        if (! $added) {
            return ['ok' => false, 'error' => 'cap_or_session_rejected', 'count' => $alreadyCreated];
        }

        GeneratePlannedEntryJob::dispatch($sessionId, (string) $decorated['id']);

        return [
            'ok' => true,
            'id' => (string) $decorated['id'],
            'entry_id' => $entryId,
            'collection' => $collectionHandle,
            'count' => $alreadyCreated + 1,
        ];
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{collection: string, blueprint: string}|null
     */
    private function validateAndCoerceTarget(array $catalog, string $collection, string $blueprint): ?array
    {
        if ($collection === '') {
            return null;
        }

        foreach ($catalog as $row) {
            if (($row['handle'] ?? '') !== $collection) {
                continue;
            }

            $blueprints = $row['blueprints'] ?? [];
            $bpHandles = array_map(fn ($b) => $b['handle'] ?? '', $blueprints);

            if ($blueprint !== '' && in_array($blueprint, $bpHandles, true)) {
                return ['collection' => $collection, 'blueprint' => $blueprint];
            }

            if (! empty($bpHandles)) {
                return ['collection' => $collection, 'blueprint' => $bpHandles[0]];
            }
        }

        // Not in the catalog snapshot — check live. The catalog is captured
        // before the tool loop, so a collection the agent just created with the
        // advanced tools (create_collection + create_blueprint) is missing from
        // it even though entries can already be planned into it.
        $live = Collection::findByHandle($collection);
        if ($live) {
            $bpHandles = $live->entryBlueprints()
                ->reject->hidden()
                ->map(fn ($bp) => (string) $bp->handle())
                ->values()
                ->all();

            if ($blueprint !== '' && in_array($blueprint, $bpHandles, true)) {
                return ['collection' => $collection, 'blueprint' => $blueprint];
            }

            if ($bpHandles !== []) {
                return ['collection' => $collection, 'blueprint' => $bpHandles[0]];
            }
        }

        return null;
    }
}

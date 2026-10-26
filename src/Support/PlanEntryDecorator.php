<?php

namespace BoldWeb\StatamicAiAssistant\Support;

use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use Illuminate\Support\Str;

/**
 * Decorate planner-emitted plan rows with a stable id and human collection / blueprint titles.
 *
 * Shared by EntryGeneratorController (single-row stream entry point) and the
 * agentic planner job (one row per create_entry_job tool call).
 */
class PlanEntryDecorator
{
    public function __construct(private EntryGeneratorService $generator) {}

    /**
     * Decorate a single plan row. Useful for the agentic planner that adds rows one at a time.
     *
     * @param  array{collection: string, blueprint: string, prompt: string, label: string, entry_id?: string}  $entry
     * @return array{id: string, collection: string, blueprint: string, prompt: string, label: string, collection_title: string, blueprint_title: string, entry_id: ?string}
     */
    public function decorateOne(array $entry): array
    {
        return $this->buildDecorated($entry, $this->buildTitleMap($this->generator->getCollectionsCatalog()));
    }

    /**
     * Decorate a list of plan rows (used by the controller for the non-agentic single entry).
     *
     * @param  array<int, array{collection: string, blueprint: string, prompt: string, label: string}>  $entries
     * @return array<int, array{id: string, collection: string, blueprint: string, prompt: string, label: string, collection_title: string, blueprint_title: string}>
     */
    public function decorateMany(array $entries): array
    {
        $titleMap = $this->buildTitleMap($this->generator->getCollectionsCatalog());

        $decorated = [];
        foreach ($entries as $entry) {
            $decorated[] = $this->buildDecorated($entry, $titleMap);
        }

        return $decorated;
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array<string, array{title: string, blueprints: array<string, string>}>
     */
    private function buildTitleMap(array $catalog): array
    {
        $titleMap = [];
        foreach ($catalog as $row) {
            $bps = [];
            foreach (($row['blueprints'] ?? []) as $bp) {
                $bps[$bp['handle'] ?? ''] = $bp['title'] ?? '';
            }
            $titleMap[$row['handle'] ?? ''] = ['title' => $row['title'] ?? '', 'blueprints' => $bps];
        }

        return $titleMap;
    }

    /**
     * @param  array{collection?: string, blueprint?: string, prompt?: string, label?: string, entry_id?: ?string}  $entry
     * @param  array<string, array{title: string, blueprints: array<string, string>}>  $titleMap
     * @return array{id: string, collection: string, blueprint: string, prompt: string, label: string, collection_title: string, blueprint_title: string, entry_id: ?string}
     */
    private function buildDecorated(array $entry, array $titleMap): array
    {
        $coll = (string) ($entry['collection'] ?? '');
        $bp = (string) ($entry['blueprint'] ?? '');
        $entryId = isset($entry['entry_id']) && is_string($entry['entry_id']) && $entry['entry_id'] !== ''
            ? $entry['entry_id']
            : null;

        return [
            'id' => (string) Str::uuid(),
            'collection' => $coll,
            'blueprint' => $bp,
            'prompt' => (string) ($entry['prompt'] ?? ''),
            'label' => (string) ($entry['label'] ?? ''),
            'collection_title' => $titleMap[$coll]['title'] ?? $coll,
            'blueprint_title' => $titleMap[$coll]['blueprints'][$bp] ?? $bp,
            'entry_id' => $entryId,
        ];
    }
}

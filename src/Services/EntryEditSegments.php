<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;

/**
 * An entry's texts and values as numbered segments for the Claude connector to
 * edit in place, and the edits applied back. Untouched content stays
 * byte-identical.
 */
final class EntryEditSegments
{
    public function __construct(private FieldTextCollector $collector) {}

    /**
     * Every text the entry holds itself, for editing it in place. Unlike
     * {@see externalSegments()}, fields marked localizable: false count too,
     * because the edit writes back into the same entry.
     *
     * @return array<int, array{id: string, text: string, html: bool}>
     */
    public function editableSegments(mixed $entry): array
    {
        $this->collectForEdit($entry);

        $segments = $this->collectedSegments();
        foreach ($this->collector->segments->collectedValues as $index => $collected) {
            $segments[] = $this->valueSegment($index, $collected);
        }

        return $segments;
    }

    /**
     * Put edited text and values back into the same entry, keyed by the ids
     * from {@see editableSegments()}. Segments left out keep their current
     * value exactly. Values must already be validated. The entry is not saved.
     *
     * @param  array<string, mixed>  $editsById
     */
    public function applySegmentEdits(mixed $entry, array $editsById): StatamicEntry
    {
        [$data, $withPlaceholders] = $this->collectForEdit($entry);

        $edits = [];
        foreach ($editsById as $id => $new) {
            $id = (string) $id;
            if (str_starts_with($id, 'v') && array_key_exists((int) substr($id, 1), $this->collector->segments->collectedValues)) {
                $this->collector->segments->valueEdits[(int) substr($id, 1)] = $new;
            } elseif (isset($this->collector->segments->collectedTexts[(int) $id]) && is_string($new)) {
                $edits[(int) $id] = $new;
            }
        }

        $entry->data(array_merge($data, $this->collector->segments->replaceInFields($withPlaceholders, $edits)));

        return $entry;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function collectForEdit(mixed $entry): array
    {
        $this->collector->segments->resetCollector();
        $blueprint = $entry->blueprint();
        $fields = $blueprint ? (new TranslatableFields)->getFieldDefinitions($blueprint) : [];
        $data = $entry->data()->toArray();

        $this->collector->collectingValues = true;
        try {
            $withPlaceholders = $this->collector->collectFromEntryFields($data, $fields, 0, 0, true)
                + $this->collector->collectMissingValueFields($data, $fields, $entry->hasOrigin());
        } finally {
            $this->collector->collectingValues = false;
        }

        return [$data, $withPlaceholders];
    }

    /**
     * @param  array{value: mixed, def: array<string, mixed>, set: string|null}  $collected
     * @return array<string, mixed>
     */
    private function valueSegment(int $index, array $collected): array
    {
        $def = $collected['def'];
        $type = $def['type'] ?? '';
        $value = $collected['value'] === FieldTextCollector::MISSING_VALUE ? null : $collected['value'];
        $segment = [
            'id' => 'v'.$index,
            'kind' => match ($type) {
                'toggle' => 'toggle',
                'link' => 'link',
                'assets' => 'asset',
                default => 'choice',
            },
            'field' => $def['handle'] ?? null,
            'label' => $def['display'] ?? null,
            'location' => $collected['set'],
            'value' => $value,
        ];

        if ($segment['kind'] === 'choice') {
            $segment['options'] = $def['options'] ?? [];
            $segment['multiple'] = (bool) ($def['multiple'] ?? false);
            $segment['custom_values_allowed'] = (bool) ($def['taggable'] ?? false);
        }
        if ($segment['kind'] === 'asset') {
            $segment['container'] = $def['container'] ?? null;
            $segment['folder'] = $def['folder'] ?? '';
            $segment['restrict_to_folder'] = (bool) ($def['restrict'] ?? false);
            $segment['multiple'] = ($def['max_files'] ?? 0) !== 1;
        }
        if ($segment['kind'] === 'link' && is_string($value) && str_starts_with($value, 'entry::')) {
            $linked = Entry::find(substr($value, 7));
            $segment['linked_entry'] = $linked ? EntryLabel::for($linked) : null;
        }

        return $segment;
    }

    /**
     * @return array<int, array{id: string, text: string, html: bool}>
     */
    public function collectedSegments(): array
    {
        $segments = [];
        foreach ($this->collector->segments->collectedTexts as $index => $text) {
            $segments[] = [
                'id' => (string) $index,
                'text' => $text,
                'html' => isset($this->collector->segments->htmlTexts[$index]),
            ];
        }

        return $segments;
    }
}

<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * What editors can write hints for: every replicator/components set and every
 * hintable field across the collection blueprints, with where each is used
 * and its saved hint attached.
 */
final class BlueprintHintTargets
{
    /**
     * Field types that never receive field hints: purely visual/config types,
     * plus replicator/components which are covered by block hints instead.
     */
    private const FIELD_HINT_SKIP_TYPES = [
        'section', 'color', 'hidden', 'spacer', 'html', 'revealer',
        'replicator', 'components', 'assets', 'section_break',
    ];

    public function __construct(private SetHintsService $hints) {}

    /**
     * Enumerate every replicator / components set across all collection
     * blueprints, grouped by set handle, and attach any saved hint.
     *
     * @return array<int, array{
     *   handle: string,
     *   title: string,
     *   ai_description: string,
     *   when_to_use: array<int, string>,
     *   locations: array<int, array{collection: string, blueprint: string, field: string}>
     * }>
     */
    public function discoverSets(): array
    {
        $sets = [];

        $this->eachTopLevelField(function ($field, array $where) use (&$sets) {
            $this->eachSetIn($field, function ($owner, string $setHandle, array $setConfig) use ($where, &$sets) {
                $sets[$setHandle] ??= [
                    'title' => is_string($setConfig['display'] ?? null)
                        ? $setConfig['display']
                        : Str::headline(str_replace('_', ' ', $setHandle)),
                    'locations' => [],
                ];

                $this->addLocation($sets[$setHandle]['locations'], $where, (string) ($owner->display() ?: $owner->handle()));
            });
        });

        return $this->hintRows($sets, $this->hints->all(), fn () => []);
    }

    /**
     * Enumerate every hintable blueprint field (top level + inside groups and
     * grids — not inside replicator/components sets, those are covered by
     * block hints) across all collection blueprints, grouped by field handle,
     * and attach any saved field hint.
     *
     * @return array<int, array{
     *   handle: string,
     *   title: string,
     *   type: string,
     *   ai_description: string,
     *   when_to_use: array<int, string>,
     *   locations: array<int, array{collection: string, blueprint: string, field: string}>
     * }>
     */
    public function discoverFields(): array
    {
        $fields = [];

        $this->eachTopLevelField(function ($field, array $where) use (&$fields) {
            $this->collectHintableField($field, $where, '', $fields);
        });

        return $this->hintRows($fields, $this->hints->allFieldHints(), fn (array $meta) => ['type' => $meta['type']]);
    }

    /**
     * Visit every top-level field of every visible collection entry blueprint,
     * with where it sits (see addLocation()).
     *
     * @param  callable(Field, array{key: string, collection: string, blueprint: string}): void  $visit
     */
    public function eachTopLevelField(callable $visit): void
    {
        foreach (Collection::all() as $collection) {
            foreach ($collection->entryBlueprints()->reject->hidden() as $blueprint) {
                $where = [
                    'key' => $collection->handle().'::'.$blueprint->handle(),
                    'collection' => (string) ($collection->title() ?: $collection->handle()),
                    'blueprint' => (string) ($blueprint->title() ?: $blueprint->handle()),
                ];

                foreach ($blueprint->fields()->all() as $field) {
                    $visit($field, $where);
                }
            }
        }
    }

    /**
     * Visit every replicator / components set reachable from $field, through
     * nested sets and grids. $owner is the replicator field holding the set.
     *
     * @param  callable(Field $owner, string $setHandle, array<string, mixed> $setConfig, Fields $setFields): void  $visit
     */
    public function eachSetIn(Field $field, callable $visit): void
    {
        $type = $field->type();

        if ($type === 'grid') {
            foreach ($this->nestedFields($field)?->all() ?? [] as $innerField) {
                $this->eachSetIn($innerField, $visit);
            }

            return;
        }

        if (! in_array($type, ['replicator', 'components'], true)) {
            return;
        }

        foreach ($field->fieldtype()->flattenedSetsConfig() as $setHandle => $setConfig) {
            $setHandle = (string) $setHandle;
            if ($setHandle === '') {
                continue;
            }

            $setFields = $this->nestedFields($field, $setHandle);
            if ($setFields === null) {
                continue;
            }

            $visit($field, $setHandle, is_array($setConfig) ? $setConfig : [], $setFields);

            foreach ($setFields->all() as $innerField) {
                $this->eachSetIn($innerField, $visit);
            }
        }
    }

    /**
     * The fields inside a group/grid, or inside one of a replicator's sets.
     * Discovery scans every blueprint, so one whose nested config cannot be
     * resolved (e.g. it imports a deleted fieldset) is logged and skipped
     * instead of breaking the page.
     */
    private function nestedFields(Field $field, ?string $setHandle = null): ?Fields
    {
        try {
            return $setHandle === null ? $field->fieldtype()->fields() : $field->fieldtype()->fields($setHandle);
        } catch (\Throwable $e) {
            Log::warning('[set-hints] skipped fields that could not be resolved', [
                'field' => $field->handle(),
                'set' => $setHandle,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, array{collection: string, blueprint: string, field: string}>  $locations
     * @param  array{key: string, collection: string, blueprint: string}  $where
     */
    public function addLocation(array &$locations, array $where, string $fieldLabel): void
    {
        $locations[$where['key'].'::'.$fieldLabel] = [
            'collection' => $where['collection'],
            'blueprint' => $where['blueprint'],
            'field' => $fieldLabel,
        ];
    }

    /**
     * One row per discovered handle with its saved hint attached, sorted by handle.
     *
     * @param  array<string, array<string, mixed>>  $found
     * @param  array<string, array{ai_description: string, when_to_use: array<int, string>}>  $hints
     * @param  callable(array<string, mixed>): array<string, mixed>  $extra  Columns between title and the hint
     * @return array<int, array<string, mixed>>
     */
    private function hintRows(array $found, array $hints, callable $extra): array
    {
        $rows = [];

        foreach ($found as $handle => $meta) {
            $existing = $hints[$handle] ?? ['ai_description' => '', 'when_to_use' => []];

            $rows[] = [
                'handle' => $handle,
                'title' => $meta['title'],
                ...$extra($meta),
                'ai_description' => $existing['ai_description'],
                'when_to_use' => $existing['when_to_use'],
                'locations' => array_values($meta['locations']),
            ];
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['handle'], $b['handle']));

        return $rows;
    }

    /**
     * Recursive helper for discoverFields — records hintable fields and walks
     * into group/grid children (skipping replicator/components sets).
     *
     * @param  array<string, array{title: string, type: string, locations: array<string, array{collection: string, blueprint: string, field: string}>}>  $fields
     */
    private function collectHintableField(Field $field, array $where, string $parentLabel, array &$fields): void
    {
        $type = $field->type();

        if (in_array($type, self::FIELD_HINT_SKIP_TYPES, true)) {
            return;
        }

        $handle = (string) $field->handle();

        if ($handle === '') {
            return;
        }

        $display = (string) ($field->display() ?: Str::headline(str_replace('_', ' ', $handle)));
        $fieldLabel = $parentLabel !== '' ? $parentLabel.' › '.$display : $display;

        $fields[$handle] ??= [
            'title' => $display,
            'type' => $type,
            'locations' => [],
        ];

        $this->addLocation($fields[$handle]['locations'], $where, $fieldLabel);

        if (in_array($type, ['group', 'grid'], true)) {
            foreach ($this->nestedFields($field)?->all() ?? [] as $child) {
                $this->collectHintableField($child, $where, $fieldLabel, $fields);
            }
        }
    }
}

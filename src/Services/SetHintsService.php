<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;
use Statamic\Facades\YAML;

/**
 * Stores and retrieves BOLD agent settings for replicator / components sets
 * (block hints) and for regular blueprint fields (field hints — hero, lead, …).
 *
 * For each set or field handle the author can configure:
 *   - ai_description : one-paragraph description of what the block/field is
 *   - when_to_use    : array of short trigger phrases describing ideal usage
 *     (for fields these act as writing guidelines, e.g. "max 60 characters")
 *
 * Both are optional. When absent, the entry is omitted from the LLM
 * catalog and the previous behaviour is preserved.
 *
 * Storage: configurable via statamic-ai-assistant.set_hints_path (default:
 * content/statamic-ai-assistant/set-hints.yaml). Legacy storage path is
 * migrated automatically on first read when the configured file is missing.
 *
 * File shape:
 *   hints:
 *     hero:
 *       ai_description: "Large, visually prominent opener …"
 *       when_to_use:
 *         - "Page introduction immediately after hero"
 *         - "Executive summary"
 *   field_hints:
 *     hero_title:
 *       ai_description: "Main page headline shown over the hero image."
 *       when_to_use:
 *         - "Keep under 60 characters"
 *
 * File shape (legacy, still parsed):
 *   hints:
 *     hero: "Use for large visual openers."
 */
class SetHintsService
{
    /**
     * Field types that never receive field hints: purely visual/config types,
     * plus replicator/components which are covered by block hints instead.
     */
    private const FIELD_HINT_SKIP_TYPES = [
        'section', 'color', 'hidden', 'spacer', 'html', 'revealer',
        'replicator', 'components', 'assets', 'section_break',
    ];

    /** @var array<string, array{ai_description: string, when_to_use: array<int, string>}>|null */
    private ?array $cache = null;

    /** @var array<string, array{ai_description: string, when_to_use: array<int, string>}>|null */
    private ?array $fieldCache = null;

    /**
     * Absolute path to the YAML file storing hints.
     */
    public function storagePath(): string
    {
        return $this->resolvedStoragePath();
    }

    /**
     * Legacy path used before set_hints_path was configurable.
     */
    public function legacyStoragePath(): string
    {
        return storage_path('app/statamic-ai-assistant/set-hints.yaml');
    }

    protected function resolvedStoragePath(): string
    {
        $path = config('statamic-ai-assistant.set_hints_path');

        if (! is_string($path) || $path === '') {
            return base_path('content/statamic-ai-assistant/set-hints.yaml');
        }

        return $this->toAbsoluteProjectPath($path);
    }

    protected function toAbsoluteProjectPath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * If the configured file is missing but the legacy storage file exists, copy once.
     */
    protected function migrateLegacyIfNeeded(): void
    {
        $path = $this->resolvedStoragePath();
        $legacy = $this->legacyStoragePath();

        if (is_file($path) || ! is_file($legacy)) {
            return;
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (! @copy($legacy, $path)) {
            Log::warning('Could not migrate set-hints.yaml from legacy storage path.', [
                'from' => $legacy,
                'to' => $path,
            ]);

            return;
        }

        Log::info('Migrated statamic-ai-assistant set-hints.yaml to versioned path.', [
            'path' => $path,
        ]);

        @unlink($legacy);
    }

    /**
     * All saved hints, keyed by set handle.
     *
     * @return array<string, array{ai_description: string, when_to_use: array<int, string>}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        return $this->cache = $this->parseSection('hints');
    }

    /**
     * All saved field hints, keyed by field handle.
     *
     * @return array<string, array{ai_description: string, when_to_use: array<int, string>}>
     */
    public function allFieldHints(): array
    {
        if ($this->fieldCache !== null) {
            return $this->fieldCache;
        }

        return $this->fieldCache = $this->parseSection('field_hints');
    }

    /**
     * Return the hint structure for a single field handle (or null when none set).
     *
     * @return array{ai_description: string, when_to_use: array<int, string>}|null
     */
    public function forField(string $fieldHandle): ?array
    {
        return $this->allFieldHints()[$fieldHandle] ?? null;
    }

    /**
     * Parse one root key of the storage file into normalized hint entries.
     *
     * @return array<string, array{ai_description: string, when_to_use: array<int, string>}>
     */
    private function parseSection(string $rootKey): array
    {
        $parsed = $this->readFile();

        $hints = [];

        if (isset($parsed[$rootKey]) && is_array($parsed[$rootKey])) {
            foreach ($parsed[$rootKey] as $handle => $value) {
                if (! is_string($handle) || $handle === '') {
                    continue;
                }

                $normalized = $this->normalizeHint($value);

                if ($normalized !== null) {
                    $hints[$handle] = $normalized;
                }
            }
        }

        return $hints;
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(): array
    {
        $this->migrateLegacyIfNeeded();

        $path = $this->storagePath();

        if (! is_file($path)) {
            return [];
        }

        try {
            $raw = (string) file_get_contents($path);
            $parsed = $raw !== '' ? YAML::parse($raw) : [];
        } catch (\Throwable $e) {
            Log::warning('Failed to parse set-hints.yaml', ['error' => $e->getMessage()]);

            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Return the hint structure for a single set (or null when none set).
     *
     * @return array{ai_description: string, when_to_use: array<int, string>}|null
     */
    public function forSet(string $setHandle): ?array
    {
        return $this->all()[$setHandle] ?? null;
    }

    /**
     * Persist the full hint map. Empty entries are removed.
     *
     * Accepts items either as a structured array
     *   ['ai_description' => '…', 'when_to_use' => ['…', '…']]
     * or as a plain string (treated as ai_description only, for legacy calls).
     *
     * @param  array<string, mixed>  $hints
     */
    public function save(array $hints): void
    {
        $this->cache = $this->writeSection('hints', $hints);
    }

    /**
     * Persist the full field-hint map (same normalization as set hints).
     *
     * @param  array<string, mixed>  $hints
     */
    public function saveFieldHints(array $hints): void
    {
        $this->fieldCache = $this->writeSection('field_hints', $hints);
    }

    /**
     * Normalize + write one root key while preserving the other sections of the file.
     *
     * @param  array<string, mixed>  $hints
     * @return array<string, array{ai_description: string, when_to_use: array<int, string>}>
     */
    private function writeSection(string $rootKey, array $hints): array
    {
        $clean = [];

        foreach ($hints as $handle => $value) {
            if (! is_string($handle) || $handle === '') {
                continue;
            }

            $normalized = $this->normalizeHint($value);

            if ($normalized !== null) {
                $clean[$handle] = $normalized;
            }
        }

        ksort($clean);

        $document = $this->readFile();
        $document[$rootKey] = $clean;

        if ($clean === []) {
            unset($document[$rootKey]);
        }

        $path = $this->storagePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, YAML::dump($document));

        return $clean;
    }

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
        $hints = $this->all();
        $sets = [];

        foreach (Collection::all() as $collection) {
            $collectionHandle = (string) $collection->handle();
            $collectionTitle = (string) $collection->title();

            foreach ($collection->entryBlueprints()->reject->hidden() as $blueprint) {
                $blueprintHandle = (string) $blueprint->handle();
                $blueprintTitle = (string) $blueprint->title();

                foreach ($blueprint->fields()->all() as $field) {
                    $this->collectSetsFromField(
                        $field,
                        $collectionHandle,
                        $collectionTitle,
                        $blueprintHandle,
                        $blueprintTitle,
                        $sets
                    );
                }
            }
        }

        $rows = [];

        foreach ($sets as $handle => $meta) {
            $existing = $hints[$handle] ?? ['ai_description' => '', 'when_to_use' => []];

            $rows[] = [
                'handle' => $handle,
                'title' => $meta['title'],
                'ai_description' => $existing['ai_description'],
                'when_to_use' => $existing['when_to_use'],
                'locations' => array_values($meta['locations']),
            ];
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['handle'], $b['handle']));

        return $rows;
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
        $hints = $this->allFieldHints();
        $fields = [];

        foreach (Collection::all() as $collection) {
            $collectionHandle = (string) $collection->handle();
            $collectionTitle = (string) $collection->title();

            foreach ($collection->entryBlueprints()->reject->hidden() as $blueprint) {
                $blueprintHandle = (string) $blueprint->handle();
                $blueprintTitle = (string) $blueprint->title();

                foreach ($blueprint->fields()->all() as $field) {
                    $this->collectHintableField(
                        $field,
                        $collectionHandle,
                        $collectionTitle,
                        $blueprintHandle,
                        $blueprintTitle,
                        '',
                        $fields
                    );
                }
            }
        }

        $rows = [];

        foreach ($fields as $handle => $meta) {
            $existing = $hints[$handle] ?? ['ai_description' => '', 'when_to_use' => []];

            $rows[] = [
                'handle' => $handle,
                'title' => $meta['title'],
                'type' => $meta['type'],
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
    private function collectHintableField(
        \Statamic\Fields\Field $field,
        string $collectionHandle,
        string $collectionTitle,
        string $blueprintHandle,
        string $blueprintTitle,
        string $parentLabel,
        array &$fields,
    ): void {
        $type = $field->type();

        if (in_array($type, self::FIELD_HINT_SKIP_TYPES, true)) {
            return;
        }

        $handle = (string) $field->handle();

        if ($handle === '') {
            return;
        }

        $display = (string) ($field->display() ?: \Illuminate\Support\Str::headline(str_replace('_', ' ', $handle)));
        $fieldLabel = $parentLabel !== '' ? $parentLabel.' › '.$display : $display;

        if (! isset($fields[$handle])) {
            $fields[$handle] = [
                'title' => $display,
                'type' => $type,
                'locations' => [],
            ];
        }

        $locationKey = $collectionHandle.'::'.$blueprintHandle.'::'.$fieldLabel;
        $fields[$handle]['locations'][$locationKey] = [
            'collection' => $collectionTitle !== '' ? $collectionTitle : $collectionHandle,
            'blueprint' => $blueprintTitle !== '' ? $blueprintTitle : $blueprintHandle,
            'field' => $fieldLabel,
        ];

        if (in_array($type, ['group', 'grid'], true)) {
            try {
                $children = $field->fieldtype()->fields();
            } catch (\Throwable) {
                return;
            }

            foreach ($children->all() as $child) {
                $this->collectHintableField(
                    $child,
                    $collectionHandle,
                    $collectionTitle,
                    $blueprintHandle,
                    $blueprintTitle,
                    $fieldLabel,
                    $fields
                );
            }
        }
    }

    /**
     * Inspect a single field handle across all blueprints and gather what the
     * LLM needs to suggest a description and writing guidelines for it.
     *
     * @return array{
     *   handle: string,
     *   title: string,
     *   type: string,
     *   instructions: string,
     *   options: array<int, string>,
     *   character_limit: ?int,
     *   locations: array<int, array{collection: string, blueprint: string, field: string}>
     * }|null
     */
    public function collectFieldContext(string $fieldHandle): ?array
    {
        if ($fieldHandle === '') {
            return null;
        }

        $found = null;

        foreach ($this->discoverFields() as $row) {
            if ($row['handle'] === $fieldHandle) {
                $found = $row;
                break;
            }
        }

        if ($found === null) {
            return null;
        }

        // Sample config details (instructions, options, character limit) from
        // the first blueprint that carries the field.
        $instructions = '';
        $options = [];
        $characterLimit = null;

        foreach (Collection::all() as $collection) {
            foreach ($collection->entryBlueprints()->reject->hidden() as $blueprint) {
                $field = $blueprint->fields()->all()->get($fieldHandle);

                if ($field === null) {
                    continue;
                }

                $instructions = trim((string) ($field->instructions() ?? ''));
                $options = $this->extractFieldOptions($field);
                $limit = $field->get('character_limit');
                $characterLimit = is_numeric($limit) ? (int) $limit : null;

                break 2;
            }
        }

        return [
            'handle' => $found['handle'],
            'title' => $found['title'],
            'type' => $found['type'],
            'instructions' => $instructions,
            'options' => $options,
            'character_limit' => $characterLimit,
            'locations' => $found['locations'],
        ];
    }

    /**
     * Inspect a single set across all blueprints and gather everything
     * the LLM needs to suggest a description and usage tips.
     *
     * Returns null when the handle is not found in any blueprint.
     *
     * @return array{
     *   handle: string,
     *   title: string,
     *   instructions: string,
     *   inner_fields: array<int, array{handle: string, type: string, display: string, instructions: string, options: array<int, string>}>,
     *   locations: array<int, array{collection: string, blueprint: string, field: string}>
     * }|null
     */
    public function collectSetContext(string $setHandle): ?array
    {
        if ($setHandle === '') {
            return null;
        }

        $title = '';
        $instructions = '';
        $innerFields = [];
        $locations = [];
        $sampledInner = false;

        foreach (Collection::all() as $collection) {
            $collectionHandle = (string) $collection->handle();
            $collectionTitle = (string) $collection->title();

            foreach ($collection->entryBlueprints()->reject->hidden() as $blueprint) {
                $blueprintHandle = (string) $blueprint->handle();
                $blueprintTitle = (string) $blueprint->title();

                foreach ($blueprint->fields()->all() as $field) {
                    $this->matchSetInField(
                        $field,
                        $setHandle,
                        $collectionHandle,
                        $collectionTitle,
                        $blueprintHandle,
                        $blueprintTitle,
                        $title,
                        $instructions,
                        $innerFields,
                        $locations,
                        $sampledInner
                    );
                }
            }
        }

        if ($locations === [] && $title === '') {
            return null;
        }

        if ($title === '') {
            $title = \Illuminate\Support\Str::headline(str_replace('_', ' ', $setHandle));
        }

        return [
            'handle' => $setHandle,
            'title' => $title,
            'instructions' => $instructions,
            'inner_fields' => $innerFields,
            'locations' => array_values($locations),
        ];
    }

    /**
     * Recursive helper for collectSetContext — mirrors collectSetsFromField but
     * captures inner-field metadata and instructions for one specific handle.
     *
     * @param  array<int, array{handle: string, type: string, display: string, instructions: string, options: array<int, string>}>  $innerFields
     * @param  array<string, array{collection: string, blueprint: string, field: string}>  $locations
     */
    private function matchSetInField(
        \Statamic\Fields\Field $field,
        string $targetHandle,
        string $collectionHandle,
        string $collectionTitle,
        string $blueprintHandle,
        string $blueprintTitle,
        string &$title,
        string &$instructions,
        array &$innerFields,
        array &$locations,
        bool &$sampledInner,
    ): void {
        $type = $field->type();

        if (in_array($type, ['replicator', 'components'], true)) {
            try {
                $fieldtype = $field->fieldtype();
                $setsConfig = $fieldtype->flattenedSetsConfig();
            } catch (\Throwable) {
                return;
            }

            foreach ($setsConfig as $setHandle => $setConfig) {
                $setHandle = (string) $setHandle;

                if ($setHandle === $targetHandle) {
                    if ($title === '' && is_array($setConfig) && isset($setConfig['display']) && is_string($setConfig['display'])) {
                        $title = $setConfig['display'];
                    }

                    if ($instructions === '' && is_array($setConfig) && isset($setConfig['instructions']) && is_string($setConfig['instructions'])) {
                        $instructions = trim($setConfig['instructions']);
                    }

                    $locKey = $collectionHandle.'::'.$blueprintHandle.'::'.$field->handle();
                    $locations[$locKey] = [
                        'collection' => $collectionTitle !== '' ? $collectionTitle : $collectionHandle,
                        'blueprint' => $blueprintTitle !== '' ? $blueprintTitle : $blueprintHandle,
                        'field' => (string) ($field->display() ?: $field->handle()),
                    ];

                    if (! $sampledInner) {
                        try {
                            $setFields = $fieldtype->fields($setHandle);
                            foreach ($setFields->all() as $f) {
                                $innerFields[] = [
                                    'handle' => (string) $f->handle(),
                                    'type' => (string) $f->type(),
                                    'display' => (string) ($f->display() ?: $f->handle()),
                                    'instructions' => (string) ($f->instructions() ?? ''),
                                    'options' => $this->extractFieldOptions($f),
                                ];
                            }
                            if ($innerFields !== []) {
                                $sampledInner = true;
                            }
                        } catch (\Throwable) {
                            // ignore — best-effort sampling
                        }
                    }
                }

                // Recurse into the set's inner fields to find nested matches.
                try {
                    $innerFieldsForRecursion = $fieldtype->fields($setHandle);
                } catch (\Throwable) {
                    continue;
                }

                foreach ($innerFieldsForRecursion->all() as $innerField) {
                    $this->matchSetInField(
                        $innerField,
                        $targetHandle,
                        $collectionHandle,
                        $collectionTitle,
                        $blueprintHandle,
                        $blueprintTitle,
                        $title,
                        $instructions,
                        $innerFields,
                        $locations,
                        $sampledInner
                    );
                }
            }

            return;
        }

        if ($type === 'grid') {
            try {
                $innerFieldsForRecursion = $field->fieldtype()->fields();
            } catch (\Throwable) {
                return;
            }

            foreach ($innerFieldsForRecursion->all() as $innerField) {
                $this->matchSetInField(
                    $innerField,
                    $targetHandle,
                    $collectionHandle,
                    $collectionTitle,
                    $blueprintHandle,
                    $blueprintTitle,
                    $title,
                    $instructions,
                    $innerFields,
                    $locations,
                    $sampledInner
                );
            }
        }
    }

    /**
     * Pull human-readable option labels (or values) from a field config.
     *
     * @return array<int, string>
     */
    private function extractFieldOptions(\Statamic\Fields\Field $field): array
    {
        $options = $field->get('options', []);

        if (! is_array($options) || $options === []) {
            return [];
        }

        $out = [];

        foreach ($options as $key => $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            } elseif (is_string($key) && $key !== '') {
                $out[] = $key;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 12);
    }

    /**
     * Coerce a hint value into the canonical structure, or return null
     * when the resulting entry would be empty.
     *
     * @return array{ai_description: string, when_to_use: array<int, string>}|null
     */
    private function normalizeHint(mixed $value): ?array
    {
        if (is_string($value)) {
            $desc = trim($value);

            return $desc !== '' ? ['ai_description' => $desc, 'when_to_use' => []] : null;
        }

        if (! is_array($value)) {
            return null;
        }

        $desc = isset($value['ai_description']) && is_string($value['ai_description'])
            ? trim($value['ai_description'])
            : '';

        $tipsRaw = $value['when_to_use'] ?? [];
        $tips = [];

        if (is_array($tipsRaw)) {
            foreach ($tipsRaw as $tip) {
                if (! is_string($tip)) {
                    continue;
                }

                $t = trim($tip);

                if ($t !== '') {
                    $tips[] = $t;
                }
            }
        }

        if ($desc === '' && $tips === []) {
            return null;
        }

        return [
            'ai_description' => $desc,
            'when_to_use' => array_values(array_unique($tips)),
        ];
    }

    /**
     * Recursively walk a field tree, collecting every replicator / components
     * set we encounter into the $sets accumulator keyed by set handle.
     *
     * @param  array<string, array{title: string, locations: array<string, array{collection: string, blueprint: string, field: string}>}>  $sets
     */
    private function collectSetsFromField(
        \Statamic\Fields\Field $field,
        string $collectionHandle,
        string $collectionTitle,
        string $blueprintHandle,
        string $blueprintTitle,
        array &$sets,
    ): void {
        $type = $field->type();

        if (in_array($type, ['replicator', 'components'], true)) {
            try {
                $fieldtype = $field->fieldtype();
                $setsConfig = $fieldtype->flattenedSetsConfig();
            } catch (\Throwable) {
                return;
            }

            foreach ($setsConfig as $setHandle => $setConfig) {
                $setHandle = (string) $setHandle;

                if ($setHandle === '') {
                    continue;
                }

                $title = is_array($setConfig) && isset($setConfig['display']) && is_string($setConfig['display'])
                    ? $setConfig['display']
                    : \Illuminate\Support\Str::headline(str_replace('_', ' ', $setHandle));

                if (! isset($sets[$setHandle])) {
                    $sets[$setHandle] = [
                        'title' => $title,
                        'locations' => [],
                    ];
                }

                $locationKey = $collectionHandle.'::'.$blueprintHandle.'::'.$field->handle();

                $sets[$setHandle]['locations'][$locationKey] = [
                    'collection' => $collectionTitle !== '' ? $collectionTitle : $collectionHandle,
                    'blueprint' => $blueprintTitle !== '' ? $blueprintTitle : $blueprintHandle,
                    'field' => (string) ($field->display() ?: $field->handle()),
                ];

                try {
                    $innerFields = $fieldtype->fields($setHandle);
                } catch (\Throwable) {
                    continue;
                }

                foreach ($innerFields->all() as $innerField) {
                    $this->collectSetsFromField(
                        $innerField,
                        $collectionHandle,
                        $collectionTitle,
                        $blueprintHandle,
                        $blueprintTitle,
                        $sets
                    );
                }
            }

            return;
        }

        if ($type === 'grid') {
            try {
                $innerFields = $field->fieldtype()->fields();
            } catch (\Throwable) {
                return;
            }

            foreach ($innerFields->all() as $innerField) {
                $this->collectSetsFromField(
                    $innerField,
                    $collectionHandle,
                    $collectionTitle,
                    $blueprintHandle,
                    $blueprintTitle,
                    $sets
                );
            }
        }
    }
}

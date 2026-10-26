<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Support\BlueprintFieldValidator;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;

/**
 * Adds or modifies fields on an existing blueprint. Merges by default —
 * matching handles are replaced in place inside their tab/section, genuinely
 * new fields are appended — so the editor's tab organisation survives
 * (structure-preserving merge adapted from cboxdk/statamic-mcp). A full
 * replace requires the explicit replace_fields flag.
 */
class UpdateBlueprintTool extends AbstractAdvancedTool
{
    use ResolvesBlueprintNamespace;

    public function __construct(private BlueprintFieldValidator $validator = new BlueprintFieldValidator) {}

    public function name(): string
    {
        return 'update_blueprint';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_blueprint',
                'description' => 'Add or modify fields on an EXISTING blueprint (collection entry, taxonomy term, or FORM blueprint via the form parameter). By default the given fields are MERGED: '
                    .'a field whose handle already exists is replaced in place, new handles are appended — other fields and the tab layout are kept. '
                    .'Read the blueprint first with read_blueprint. Changes apply IMMEDIATELY — only do this when the user explicitly asked for it. '
                    .'Removing a field is NOT supported (set replace_fields=true only when the user explicitly wants to replace ALL fields).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => [
                            'type' => 'string',
                            'description' => 'Handle of the blueprint to update.',
                        ],
                        'collection' => [
                            'type' => 'string',
                            'description' => 'Collection handle the blueprint belongs to.',
                        ],
                        'taxonomy' => [
                            'type' => 'string',
                            'description' => 'Taxonomy handle the blueprint belongs to.',
                        ],
                        'form' => [
                            'type' => 'string',
                            'description' => 'Form handle, for FORM blueprints (e.g. a contact form — pass the form handle as "handle" too).',
                        ],
                        'fields' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                            'description' => 'Fields to add/replace: [{"handle": "...", "field": {"type": "...", ...}}, ...]. An {"import": "<fieldset_handle>"} row appends a reusable fieldset (see list_fieldsets).',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Optional new blueprint title.',
                        ],
                        'replace_fields' => [
                            'type' => 'boolean',
                            'description' => 'DANGEROUS: true replaces ALL existing fields with the given ones. Default false (merge).',
                        ],
                    ],
                    'required' => ['handle', 'fields'],
                ],
            ],
        ];
    }

    protected function run(array $args, ToolContext $context): array
    {
        $handle = $this->stringArg($args, 'handle');
        if ($handle === '') {
            return ['ok' => false, 'error' => 'Blueprint handle is required.'];
        }

        $ns = $this->resolveBlueprintNamespace($args);
        if (! $ns['ok']) {
            return $ns;
        }

        $blueprint = $this->findBlueprintIn($ns['namespace'], $handle);
        if (! $blueprint) {
            return ['ok' => false, 'error' => "Blueprint \"{$handle}\" not found in {$ns['owner_type']} \"{$ns['owner']}\". Use list_blueprints to see what exists, or create_blueprint for a new one."];
        }

        $fields = isset($args['fields']) && is_array($args['fields']) ? $args['fields'] : [];
        $validation = $this->validator->validate($fields);
        if (! $validation['ok']) {
            return $validation;
        }

        $contents = $blueprint->contents();

        $title = $this->stringArg($args, 'title');
        if ($title !== '') {
            $contents['title'] = $title;
        }

        if (($args['replace_fields'] ?? false) === true) {
            unset($contents['tabs']);
            $contents['fields'] = $validation['fields'];
        } else {
            $contents = $this->mergeFieldsIntoContents($contents, $validation['fields']);
        }

        $context->reportActivity((string) __('Updating blueprint :handle', ['handle' => $handle]));

        $blueprint->setContents($contents);

        if ($resolveError = $this->blueprintResolutionError($blueprint)) {
            return ['ok' => false, 'error' => $resolveError];
        }

        $blueprint->save();

        return [
            'ok' => true,
            'updated' => true,
            'handle' => $blueprint->handle(),
            'title' => $blueprint->title(),
            $ns['owner_type'] => $ns['owner'],
            'fields' => $blueprint->fields()->all()->map(fn ($f) => $f->handle())->values()->all(),
        ];
    }

    /**
     * Structure-preserving merge: replace matching handles in place inside the
     * tabs→sections→fields tree, append genuinely new fields to the first
     * section of the first tab. Falls back to a flat `fields` list when the
     * blueprint has no tab structure.
     *
     * @param  array<string, mixed>  $contents
     * @param  array<int, array{handle: string, field: array<string, mixed>}>  $newFields
     * @return array<string, mixed>
     */
    private function mergeFieldsIntoContents(array $contents, array $newFields): array
    {
        // Fieldset imports have no handle to match on: they are append-only,
        // skipped when an identical import row already exists in the blueprint.
        $appendImports = array_values(array_filter(
            array_filter($newFields, fn ($f) => isset($f['import'])),
            fn ($import) => ! $this->containsImportRow($contents, $import),
        ));

        $newByHandle = [];
        foreach ($newFields as $field) {
            if (isset($field['handle'])) {
                $newByHandle[$field['handle']] = $field;
            }
        }

        if (isset($contents['tabs']) && is_array($contents['tabs'])) {
            $updated = [];

            foreach ($contents['tabs'] as $tabName => $tab) {
                if (! is_array($tab['sections'] ?? null)) {
                    continue;
                }

                foreach ($tab['sections'] as $si => $section) {
                    if (! is_array($section['fields'] ?? null)) {
                        continue;
                    }

                    foreach ($section['fields'] as $fi => $existing) {
                        $h = is_array($existing) ? ($existing['handle'] ?? null) : null;

                        if (is_string($h) && isset($newByHandle[$h])) {
                            $contents['tabs'][$tabName]['sections'][$si]['fields'][$fi] = $newByHandle[$h];
                            $updated[$h] = true;
                        }
                    }
                }
            }

            $remaining = array_merge(array_values(array_diff_key($newByHandle, $updated)), $appendImports);

            if ($remaining !== []) {
                $firstTab = array_key_first($contents['tabs']);

                if ($firstTab !== null) {
                    if (! is_array($contents['tabs'][$firstTab]['sections'] ?? null) || $contents['tabs'][$firstTab]['sections'] === []) {
                        $contents['tabs'][$firstTab]['sections'] = [['fields' => []]];
                    }

                    $firstSection = array_key_first($contents['tabs'][$firstTab]['sections']);

                    if (! is_array($contents['tabs'][$firstTab]['sections'][$firstSection]['fields'] ?? null)) {
                        $contents['tabs'][$firstTab]['sections'][$firstSection]['fields'] = [];
                    }

                    foreach ($remaining as $field) {
                        $contents['tabs'][$firstTab]['sections'][$firstSection]['fields'][] = $field;
                    }
                }
            }

            return $contents;
        }

        // Flat `fields` shape (how create_blueprint stores them).
        $fields = is_array($contents['fields'] ?? null) ? $contents['fields'] : [];
        $updated = [];

        foreach ($fields as $i => $existing) {
            $h = is_array($existing) ? ($existing['handle'] ?? null) : null;

            if (is_string($h) && isset($newByHandle[$h])) {
                $fields[$i] = $newByHandle[$h];
                $updated[$h] = true;
            }
        }

        foreach (array_merge(array_values(array_diff_key($newByHandle, $updated)), $appendImports) as $field) {
            $fields[] = $field;
        }

        $contents['fields'] = array_values($fields);

        return $contents;
    }

    /**
     * Whether the blueprint contents already contain an identical fieldset
     * import row (same fieldset + prefix), anywhere in tabs or the flat list.
     *
     * @param  array<string, mixed>  $contents
     * @param  array<string, string>  $import
     */
    private function containsImportRow(array $contents, array $import): bool
    {
        $matches = function ($row) use ($import): bool {
            return is_array($row)
                && ($row['import'] ?? null) === $import['import']
                && ($row['prefix'] ?? null) === ($import['prefix'] ?? null);
        };

        foreach (($contents['fields'] ?? []) as $row) {
            if ($matches($row)) {
                return true;
            }
        }

        if (is_array($contents['tabs'] ?? null)) {
            foreach ($contents['tabs'] as $tab) {
                foreach ((is_array($tab['sections'] ?? null) ? $tab['sections'] : []) as $section) {
                    foreach ((is_array($section['fields'] ?? null) ? $section['fields'] : []) as $row) {
                        if ($matches($row)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}

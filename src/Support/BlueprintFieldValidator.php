<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Exceptions\FieldtypeNotFoundException;
use Statamic\Facades\Collection;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\FieldtypeRepository;

/**
 * Strict validation for LLM-provided blueprint field definitions (adapted from
 * cboxdk/statamic-mcp). The expected shape per field is:
 *
 *   {"handle": "title", "field": {"type": "text", "display": "Title"}}
 *
 * No auto-wrapping: near-miss mistakes ("name" instead of "handle", "type" at
 * the top level, "fieldtype" instead of "type") get a targeted correction hint
 * so a small model can fix its own call. Fieldtypes must exist (unknown types
 * get the full list of available ones), referenced taxonomies/collections must
 * exist, and Antlers template expressions are stripped from string config
 * values so a blueprint can never smuggle template code into the site.
 */
class BlueprintFieldValidator
{
    /** Common near-miss config keys LLMs send incorrectly. */
    private const PARAM_CORRECTIONS = [
        'taxonomy' => 'taxonomies',
        'collection' => 'collections',
        'field_type' => 'type',
        'fieldtype' => 'type',
        'name' => 'handle',
    ];

    /**
     * @param  array<int|string, mixed>  $fields
     * @return array{ok: true, fields: array<int, array{handle: string, field: array<string, mixed>}>}|array{ok: false, error: string}
     */
    public function validate(array $fields): array
    {
        $seenHandles = [];

        return $this->validateFieldList($fields, $seenHandles);
    }

    /**
     * Validate an LLM-provided tabbed blueprint layout and normalize it to
     * Statamic's `tabs` contents (keyed by tab handle):
     *
     *   [{"handle": "main", "display": "Main", "sections": [{"display": "...", "instructions": "...", "fields": [...]}]}]
     *
     * Field handle uniqueness is enforced blueprint-wide (across all tabs and
     * sections), and every section's fields go through the same strict field
     * validation as flat lists.
     *
     * @param  array<int|string, mixed>  $tabs
     * @return array{ok: true, tabs: array<string, array<string, mixed>>}|array{ok: false, error: string}
     */
    public function validateTabs(array $tabs): array
    {
        if ($tabs === []) {
            return $this->error('Provide at least one tab as {"handle": "main", "sections": [{"fields": [...]}]}.');
        }

        $seenHandles = [];
        $normalized = [];

        foreach (array_values($tabs) as $ti => $tab) {
            if (! is_array($tab)) {
                return $this->error("Tab at index {$ti} must be an object: {\"handle\": \"main\", \"sections\": [{\"fields\": [...]}]}.");
            }

            $tabHandle = is_string($tab['handle'] ?? null) ? trim($tab['handle']) : '';
            if (preg_match('/^[a-z][a-z0-9_]*$/', $tabHandle) !== 1) {
                return $this->error("Tab at index {$ti} needs a snake_case \"handle\" (e.g. \"main\", \"seo\", \"sidebar\").");
            }
            if (isset($normalized[$tabHandle])) {
                return $this->error("Duplicate tab handle \"{$tabHandle}\". Each tab must have a unique handle.");
            }

            $sections = is_array($tab['sections'] ?? null) ? array_values($tab['sections']) : [];
            // Convenience: a tab with "fields" directly on it becomes its single section.
            if ($sections === [] && is_array($tab['fields'] ?? null)) {
                $sections = [['fields' => $tab['fields']]];
            }
            if ($sections === []) {
                return $this->error("Tab \"{$tabHandle}\" must have \"sections\": [{\"fields\": [...]}] with at least one section.");
            }

            $sectionsOut = [];
            foreach ($sections as $si => $section) {
                if (! is_array($section) || ! is_array($section['fields'] ?? null)) {
                    return $this->error("Tab \"{$tabHandle}\" section {$si} must be an object with a \"fields\" array.");
                }

                $result = $this->validateFieldList($section['fields'], $seenHandles);
                if (! $result['ok']) {
                    return $this->error("Tab \"{$tabHandle}\" section {$si}: {$result['error']}");
                }

                $sectionOut = [];
                if (is_string($section['display'] ?? null) && trim($section['display']) !== '') {
                    $sectionOut['display'] = $this->cleanLabel($section['display']);
                }
                if (is_string($section['instructions'] ?? null) && trim($section['instructions']) !== '') {
                    $sectionOut['instructions'] = $this->cleanLabel($section['instructions']);
                }
                $sectionOut['fields'] = $result['fields'];
                $sectionsOut[] = $sectionOut;
            }

            $tabOut = [];
            if (is_string($tab['display'] ?? null) && trim($tab['display']) !== '') {
                $tabOut['display'] = $this->cleanLabel($tab['display']);
            }
            $tabOut['sections'] = $sectionsOut;
            $normalized[$tabHandle] = $tabOut;
        }

        return ['ok' => true, 'tabs' => $normalized];
    }

    /**
     * Validate one flat field list. `$seenHandles` is shared by reference so
     * tabbed layouts get blueprint-wide handle uniqueness across sections.
     *
     * @param  array<int|string, mixed>  $fields
     * @param  array<string, true>  $seenHandles
     * @return array{ok: true, fields: array<int, array{handle: string, field: array<string, mixed>}>}|array{ok: false, error: string}
     */
    private function validateFieldList(array $fields, array &$seenHandles): array
    {
        if ($fields === []) {
            return ['ok' => false, 'error' => 'Provide at least one field as {"handle": "...", "field": {"type": "..."}}.'];
        }

        $fieldtypes = app(FieldtypeRepository::class);
        $validated = [];

        foreach (array_values($fields) as $index => $field) {
            if (! is_array($field)) {
                return $this->error("Field at index {$index} must be an object. Expected format: {\"handle\": \"title\", \"field\": {\"type\": \"text\", \"display\": \"Title\"}}");
            }

            // Fieldset import row: {"import": "hero", "prefix": "hero_"} pulls a
            // whole existing fieldset in by reference — the preferred way to
            // reuse shared field groups instead of redefining them.
            if (array_key_exists('import', $field)) {
                $importResult = $this->validateImportRow($field, $index);

                if (isset($importResult['error'])) {
                    return $importResult;
                }

                $validated[] = $importResult['row'];

                continue;
            }

            if (! isset($field['handle']) || ! is_string($field['handle']) || trim($field['handle']) === '') {
                $hint = isset($field['name']) ? ' Did you mean "handle"? You sent "name".' : '';

                return $this->error("Field at index {$index} must have a \"handle\" key (string).{$hint} Example: {\"handle\": \"title\", \"field\": {\"type\": \"text\"}}");
            }

            $handle = trim($field['handle']);

            if (preg_match('/^[a-z][a-z0-9_]*$/', $handle) !== 1) {
                return $this->error("Field handle \"{$handle}\" is invalid. Handles are snake_case: lowercase letters, digits and underscores, starting with a letter (e.g. \"hero_title\").");
            }

            if (isset($seenHandles[$handle])) {
                return $this->error("Duplicate field handle: \"{$handle}\". Each field must have a unique handle.");
            }
            $seenHandles[$handle] = true;

            // Single-field reference: {"handle": "hero", "field": "hero.hero_title"}
            // links one field from a fieldset instead of redefining it inline.
            if (isset($field['field']) && is_string($field['field'])) {
                $refError = $this->validateFieldReference($field['field'], $handle);

                if ($refError !== null) {
                    return $refError;
                }

                $validated[] = ['handle' => $handle, 'field' => trim($field['field'])];

                continue;
            }

            if (! isset($field['field']) || ! is_array($field['field'])) {
                $hint = array_key_exists('type', $field)
                    ? ' It looks like you put "type" at the top level. Move it inside a "field" object.'
                    : '';

                return $this->error("Field \"{$handle}\" is missing the \"field\" key.{$hint} Correct format: {\"handle\": \"{$handle}\", \"field\": {\"type\": \"text\", \"display\": \"...\"}}, or reference a fieldset field with \"field\": \"<fieldset>.<field>\".");
            }

            $config = $field['field'];

            foreach (self::PARAM_CORRECTIONS as $wrong => $correct) {
                if (isset($config[$wrong]) && ! isset($config[$correct])) {
                    return $this->error("Field \"{$handle}\" has \"{$wrong}\" in its config. Did you mean \"{$correct}\"? Rename \"{$wrong}\" to \"{$correct}\".");
                }
            }

            $type = $config['type'] ?? null;
            if (! is_string($type) || trim($type) === '') {
                return $this->error("Field \"{$handle}\" is missing \"type\" in its field config. Example: {\"handle\": \"{$handle}\", \"field\": {\"type\": \"text\"}}");
            }

            try {
                $fieldtypes->find($type);
            } catch (FieldtypeNotFoundException) {
                $available = $fieldtypes->handles()->values()->sort()->implode(', ');

                return $this->error("Unknown field type \"{$type}\" for field \"{$handle}\". Available types: {$available}");
            }

            if (isset($config['taxonomies']) && is_array($config['taxonomies'])) {
                foreach ($config['taxonomies'] as $taxHandle) {
                    if (is_string($taxHandle) && ! Taxonomy::find($taxHandle)) {
                        return $this->error("Field \"{$handle}\" references taxonomy \"{$taxHandle}\" which does not exist. Create it first with create_taxonomy.");
                    }
                }
            }

            if (isset($config['collections']) && is_array($config['collections'])) {
                foreach ($config['collections'] as $colHandle) {
                    if (is_string($colHandle) && ! Collection::find($colHandle)) {
                        return $this->error("Field \"{$handle}\" references collection \"{$colHandle}\" which does not exist. Create it first with create_collection.");
                    }
                }
            }

            // Strip Antlers template expressions from string config values.
            foreach ($config as $key => $value) {
                if (is_string($value) && (str_contains($value, '{{') || str_contains($value, '{!!'))) {
                    $config[$key] = strip_tags((string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '', $value));
                }
            }

            $validated[] = ['handle' => $handle, 'field' => $config];
        }

        return ['ok' => true, 'fields' => $validated];
    }

    /**
     * Validate an {"import": "<fieldset>", "prefix"?: "x_"} row.
     *
     * @param  array<string, mixed>  $field
     * @return array{row: array<string, string>}|array{ok: false, error: string}
     */
    private function validateImportRow(array $field, int $index): array
    {
        $import = $field['import'] ?? null;

        if (! is_string($import) || trim($import) === '') {
            return $this->error("Field at index {$index}: \"import\" must be a fieldset handle string, e.g. {\"import\": \"hero\"}.");
        }

        $import = trim($import);

        if (! Fieldset::find($import)) {
            return $this->error("Fieldset \"{$import}\" not found. Available fieldsets: ".$this->availableFieldsets());
        }

        $row = ['import' => $import];

        if (isset($field['prefix'])) {
            $prefix = is_string($field['prefix']) ? trim($field['prefix']) : '';

            if ($prefix === '' || preg_match('/^[a-z][a-z0-9_]*$/', $prefix) !== 1) {
                return $this->error("Import of \"{$import}\": \"prefix\" must be snake_case (e.g. \"hero_\").");
            }

            $row['prefix'] = $prefix;
        }

        return ['row' => $row];
    }

    /**
     * Validate a "fieldset.field" string reference.
     *
     * @return array{ok: false, error: string}|null  Null when valid.
     */
    private function validateFieldReference(string $reference, string $handle): ?array
    {
        $reference = trim($reference);
        $parts = explode('.', $reference, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return $this->error("Field \"{$handle}\": a string \"field\" value must reference a fieldset field as \"<fieldset>.<field>\" (e.g. \"hero.hero_title\").");
        }

        $fieldset = Fieldset::find($parts[0]);

        if (! $fieldset) {
            return $this->error("Field \"{$handle}\" references fieldset \"{$parts[0]}\" which does not exist. Available fieldsets: ".$this->availableFieldsets());
        }

        $fieldsetHandles = $fieldset->fields()->all()->keys()->all();

        if (! in_array($parts[1], $fieldsetHandles, true)) {
            return $this->error("Field \"{$handle}\": fieldset \"{$parts[0]}\" has no field \"{$parts[1]}\". Its fields: ".implode(', ', $fieldsetHandles));
        }

        return null;
    }

    /** Tab/section labels get the same Antlers-stripping as field config strings. */
    private function cleanLabel(string $value): string
    {
        return trim(strip_tags((string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '', $value)));
    }

    private function availableFieldsets(): string
    {
        $handles = Fieldset::all()->map(fn ($fs) => (string) $fs->handle())->sort()->values()->all();

        return $handles !== [] ? implode(', ', $handles) : '(none)';
    }

    /**
     * @return array{ok: false, error: string}
     */
    private function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}

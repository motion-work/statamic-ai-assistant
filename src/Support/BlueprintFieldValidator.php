<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Facades\Fieldset;

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
    private FieldsetReferenceRules $fieldsets;

    private FieldConfigRules $configs;

    public function __construct()
    {
        $this->fieldsets = new FieldsetReferenceRules;
        $this->configs = new FieldConfigRules;
    }

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

            $sectionsOut = $this->validateSections($tabHandle, $sections, $seenHandles);
            if (isset($sectionsOut['error'])) {
                return $sectionsOut;
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
     * @param  array<int, mixed>  $sections
     * @param  array<string, true>  $seenHandles
     * @return array<int, array<string, mixed>>|array{ok: false, error: string}
     */
    private function validateSections(string $tabHandle, array $sections, array &$seenHandles): array
    {
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

        return $sectionsOut;
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

        $validated = [];

        foreach (array_values($fields) as $index => $field) {
            $result = $this->validateFieldRow($field, $index, $seenHandles);

            if (isset($result['error'])) {
                return $result;
            }

            $validated[] = $result['row'];
        }

        return ['ok' => true, 'fields' => $validated];
    }

    /**
     * One field row: a fieldset import, a reference to a fieldset field, or an
     * inline field config. ['row' => ...] or the error result.
     *
     * @param  array<string, true>  $seenHandles
     * @return array<string, mixed>
     */
    private function validateFieldRow(mixed $field, int $index, array &$seenHandles): array
    {
        if (! is_array($field)) {
            return $this->error("Field at index {$index} must be an object. Expected format: {\"handle\": \"title\", \"field\": {\"type\": \"text\", \"display\": \"Title\"}}");
        }

        // Fieldset import row: {"import": "hero", "prefix": "hero_"} pulls a
        // whole existing fieldset in by reference — the preferred way to
        // reuse shared field groups instead of redefining them.
        if (array_key_exists('import', $field)) {
            return $this->fieldsets->validateImportRow($field, $index);
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
            $refError = $this->fieldsets->validateFieldReference($field['field'], $handle);

            if ($refError !== null) {
                return $refError;
            }

            return ['row' => ['handle' => $handle, 'field' => trim($field['field'])]];
        }

        if (! isset($field['field']) || ! is_array($field['field'])) {
            $hint = array_key_exists('type', $field)
                ? ' It looks like you put "type" at the top level. Move it inside a "field" object.'
                : '';

            return $this->error("Field \"{$handle}\" is missing the \"field\" key.{$hint} Correct format: {\"handle\": \"{$handle}\", \"field\": {\"type\": \"text\", \"display\": \"...\"}}, or reference a fieldset field with \"field\": \"<fieldset>.<field>\".");
        }

        $config = $field['field'];

        if ($error = $this->configs->configError($handle, $config)) {
            return $error;
        }

        return ['row' => ['handle' => $handle, 'field' => $this->configs->withoutAntlers($config)]];
    }

    /** Tab/section labels get the same Antlers-stripping as field config strings. */
    private function cleanLabel(string $value): string
    {
        return trim(strip_tags((string) preg_replace(FieldConfigRules::ANTLERS, '', $value)));
    }

    /**
     * @return array{ok: false, error: string}
     */
    private function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}

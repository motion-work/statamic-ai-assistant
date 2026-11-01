<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Facades\Fieldset;

/**
 * Blueprint rows that reuse a fieldset: a whole import, or one field by
 * "fieldset.field". The fieldset and field must exist.
 */
final class FieldsetReferenceRules
{
    /**
     * Validate an {"import": "<fieldset>", "prefix"?: "x_"} row.
     *
     * @param  array<string, mixed>  $field
     * @return array{row: array<string, string>}|array{ok: false, error: string}
     */
    public function validateImportRow(array $field, int $index): array
    {
        $import = $field['import'] ?? null;

        if (! is_string($import) || trim($import) === '') {
            return ['ok' => false, 'error' => "Field at index {$index}: \"import\" must be a fieldset handle string, e.g. {\"import\": \"hero\"}."];
        }

        $import = trim($import);

        if (! Fieldset::find($import)) {
            return ['ok' => false, 'error' => "Fieldset \"{$import}\" not found. Available fieldsets: ".$this->availableFieldsets()];
        }

        $row = ['import' => $import];

        if (isset($field['prefix'])) {
            $prefix = is_string($field['prefix']) ? trim($field['prefix']) : '';

            if ($prefix === '' || preg_match('/^[a-z][a-z0-9_]*$/', $prefix) !== 1) {
                return ['ok' => false, 'error' => "Import of \"{$import}\": \"prefix\" must be snake_case (e.g. \"hero_\")."];
            }

            $row['prefix'] = $prefix;
        }

        return ['row' => $row];
    }

    /**
     * Validate a "fieldset.field" string reference.
     *
     * @return array{ok: false, error: string}|null Null when valid.
     */
    public function validateFieldReference(string $reference, string $handle): ?array
    {
        $reference = trim($reference);
        $parts = explode('.', $reference, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return ['ok' => false, 'error' => "Field \"{$handle}\": a string \"field\" value must reference a fieldset field as \"<fieldset>.<field>\" (e.g. \"hero.hero_title\")."];
        }

        $fieldset = Fieldset::find($parts[0]);

        if (! $fieldset) {
            return ['ok' => false, 'error' => "Field \"{$handle}\" references fieldset \"{$parts[0]}\" which does not exist. Available fieldsets: ".$this->availableFieldsets()];
        }

        $fieldsetHandles = $fieldset->fields()->all()->keys()->all();

        if (! in_array($parts[1], $fieldsetHandles, true)) {
            return ['ok' => false, 'error' => "Field \"{$handle}\": fieldset \"{$parts[0]}\" has no field \"{$parts[1]}\". Its fields: ".implode(', ', $fieldsetHandles)];
        }

        return null;
    }

    private function availableFieldsets(): string
    {
        $handles = Fieldset::all()->map(fn ($fs) => (string) $fs->handle())->sort()->values()->all();

        return $handles !== [] ? implode(', ', $handles) : '(none)';
    }
}

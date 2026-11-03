<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Support\LlmDate;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * The model's values for single-value fieldtypes (text, markdown, numbers,
 * time, dates, choices, tables, video), coerced to what the field stores. A
 * value the field cannot take is dropped with a warning.
 */
final class ScalarValueMapper
{
    public function __construct(
        private AbstractAiService $aiService,
        private FieldOptionCatalog $options,
    ) {}

    public function mapTextValue(mixed $value, Field $field, array &$warnings): ?string
    {
        if (! is_string($value) && ! is_scalar($value)) {
            $this->warnNonScalarDrop($field, $warnings);

            return null;
        }
        $text = is_string($value) ? $value : (string) $value;

        return strip_tags($this->aiService->cleanResult($text));
    }

    /**
     * A slug field stores the hyphenated form, in the site's language, the
     * way the Control Panel generates it.
     *
     * @param  array<int, string>  $warnings
     */
    public function mapSlugValue(mixed $value, Field $field, array &$warnings, string $siteHandle): ?string
    {
        if (! is_string($value) && ! is_scalar($value)) {
            $this->warnNonScalarDrop($field, $warnings);

            return null;
        }

        $lang = Site::get($siteHandle)?->lang() ?? app()->getLocale();
        $slug = Str::slug(trim((string) $value), '-', $lang);

        return $slug !== '' ? $slug : null;
    }

    public function mapMultipleChoiceValue(mixed $value, Field $field, array &$warnings): ?array
    {
        $validValues = $this->options->choiceFieldOptionValues($field);

        // LLMs regularly send a bare string where an array is expected —
        // wrap it instead of dropping the field (same coercion the
        // statamic-mcp addon applies to relationship-style values).
        $values = match (true) {
            is_array($value) => array_values($value),
            is_scalar($value) => [$value],
            default => null,
        };

        if ($values === null) {
            $this->warnNonScalarDrop($field, $warnings);

            return null;
        }

        $picked = [];

        foreach ($values as $v) {
            if (! is_scalar($v)) {
                continue;
            }
            $sv = (string) $v;

            if ($validValues !== [] && ! in_array($sv, $validValues, true)) {
                $warnings[] = __(':field: AI selected ":value" which is not a valid option. Value skipped.', [
                    'field' => $field->display(),
                    'value' => $sv,
                ]);

                continue;
            }

            $picked[] = $sv;
        }

        $picked = array_values(array_unique($picked));

        return $picked === [] ? null : $picked;
    }

    public function mapSingleChoiceValue(mixed $value, Field $field, array &$warnings): ?string
    {
        $validValues = $this->options->choiceFieldOptionValues($field);

        if (! is_scalar($value)) {
            $this->warnNonScalarDrop($field, $warnings);

            return null;
        }
        $strValue = (string) $value;

        if ($validValues !== [] && ! in_array($strValue, $validValues, true)) {
            $warnings[] = __(':field: AI selected ":value" which is not a valid option. Field left empty.', [
                'field' => $field->display(),
                'value' => $strValue,
            ]);

            return null;
        }

        return $strValue;
    }

    public function mapDateValue(mixed $value, Field $field, array &$warnings): ?string
    {
        $date = LlmDate::parse($value);

        if ($date === null) {
            $warnings[] = __(':field: AI provided invalid date ":value". Field left empty.', [
                'field' => $field->display(),
                'value' => is_scalar($value) ? (string) $value : get_debug_type($value),
            ]);

            return null;
        }

        // Store in the shape the field keeps: time-enabled fields keep H:i,
        // plain date fields stay Y-m-d (any parsed time is dropped).
        return (bool) ($field->config()['time_enabled'] ?? false)
            ? $date->format('Y-m-d H:i')
            : $date->format('Y-m-d');
    }

    public function mapMarkdownValue(mixed $value, Field $field, array &$warnings): ?string
    {
        if (! is_scalar($value)) {
            $this->warnNonScalarDrop($field, $warnings);

            return null;
        }

        return $this->aiService->cleanResult((string) $value);
    }

    public function mapNumberValue(mixed $value, Field $field, array &$warnings): int|float|null
    {
        if (! is_numeric($value)) {
            $this->warnNonScalarDrop($field, $warnings);

            return null;
        }

        return $field->type() === 'integer' ? (int) $value : (float) $value;
    }

    public function mapTimeValue(mixed $value, Field $field, array &$warnings): ?string
    {
        if (! is_scalar($value)) {
            $this->warnNonScalarDrop($field, $warnings);

            return null;
        }

        $str = trim((string) $value);

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $str, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            $warnings[] = __(':field: AI provided invalid time ":value". Field left empty.', [
                'field' => $field->display(),
                'value' => $str,
            ]);

            return null;
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    /**
     * Normalize a table field to Statamic storage: each row {cells: [string|null, ...]}.
     * Tolerant on purpose (adapted from cboxdk/statamic-mcp): LLMs commonly send
     * bare arrays instead of {cells: ...} rows, or wrap cells as {value: x} — the
     * latter renders as "[object Object]" in the CP editor if persisted raw.
     *
     * @return array<int, array{cells: array<int, string|null>}>|null
     */
    public function mapTableFieldValue(mixed $value, Field $field, array &$warnings): ?array
    {
        if (! is_array($value)) {
            $this->warnNonScalarDrop($field, $warnings);

            return null;
        }

        $rows = [];

        foreach ($value as $row) {
            if (is_array($row) && ! array_key_exists('cells', $row) && array_is_list($row)) {
                $row = ['cells' => $row];
            }

            $cells = is_array($row) ? ($row['cells'] ?? null) : null;

            if (! is_array($cells)) {
                $warnings[] = __(':field: table row without "cells" was skipped.', ['field' => $field->display()]);

                continue;
            }

            $rows[] = ['cells' => array_map(fn ($cell) => $this->normalizeTableCell($cell), array_values($cells))];
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * Coerce one table cell to string|null, unwrapping the augmented {value: x} form.
     */
    private function normalizeTableCell(mixed $cell): ?string
    {
        if (is_array($cell) && array_key_exists('value', $cell)) {
            $cell = $cell['value'];
        }

        if ($cell === null) {
            return null;
        }

        return is_scalar($cell) ? (string) $cell : null;
    }

    public function mapVideoFieldValue(mixed $value, Field $field, array &$warnings): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            return null;
        }
        $str = is_string($value) ? trim($value) : (string) ($value ?? '');

        if ($str === '') {
            return null;
        }

        if (filter_var($str, FILTER_VALIDATE_URL)) {
            return $str;
        }

        if (preg_match('#^https?://(www\.)?(youtube\.com|youtu\.be)\S+#i', $str)) {
            return $str;
        }

        $warnings[] = __('Invalid video URL: :value', ['value' => $str]);

        return null;
    }

    /**
     * Surfaced when the AI returns a non-scalar (e.g. an array) for a field that expects
     * a scalar value. The field is dropped to avoid casting errors; the user is told why.
     */
    public function warnNonScalarDrop(Field $field, array &$warnings): void
    {
        $warnings[] = __(':field: AI returned an unexpected value shape and was skipped. Try again or rephrase the prompt.', [
            'field' => $field->display(),
        ]);
    }

    public function mapBooleanValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<int, string>  $warnings
     */
    public function mapChoiceValue(mixed $value, Field $field, array &$warnings): string|array|null
    {
        return $this->options->choiceFieldIsMultiple($field)
            ? $this->mapMultipleChoiceValue($value, $field, $warnings)
            : $this->mapSingleChoiceValue($value, $field, $warnings);
    }

    public function mapLinkFieldValue(mixed $value, Field $field, array &$warnings): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            $this->warnNonScalarDrop($field, $warnings);

            return null;
        }
        $str = is_string($value) ? trim($value) : (string) ($value ?? '');

        if ($str === '') {
            return null;
        }

        if ($str === '/') {
            return '/';
        }

        if (preg_match('/^entry::[0-9a-f-]{36}$/i', $str)) {
            return $str;
        }

        if (str_starts_with($str, 'asset::')) {
            return $str;
        }

        if ($str === '@child') {
            return $str;
        }

        if (filter_var($str, FILTER_VALIDATE_URL)) {
            return $str;
        }

        if (str_starts_with($str, '/') && strlen($str) > 1) {
            return $str;
        }

        // Anchors on the same page, e-mail and phone links: the link fieldtype
        // stores them as typed, and editors use them (e.g. "#benefits").
        if (preg_match('~^(#[\w-]+|mailto:\S+@\S+|tel:[+\d\s()/-]+)$~iu', $str)) {
            return $str;
        }

        $warnings[] = __(':field: link value ":value" is not a valid URL or Statamic link reference.', [
            'field' => $field->display(),
            'value' => $str,
        ]);

        return null;
    }
}

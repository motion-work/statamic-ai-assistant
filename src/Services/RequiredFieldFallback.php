<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Fills required fields the model left empty, where a value can be chosen
 * safely (a first option, a first term, a short generated text), and warns
 * about each one so the editor can check it.
 */
final class RequiredFieldFallback
{
    public function __construct(
        private AbstractAiService $aiService,
        private FieldOptionCatalog $options,
        private ReferenceValueMapper $references,
        private FieldValueMapper $mapper,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     * @param  array<string, string>  $warnings
     */
    public function applyMandatoryFieldFallbacks(array &$data, array &$displayData, Blueprint $blueprint, array &$warnings, string $prompt, string $siteHandle): void
    {
        foreach ($blueprint->fields()->all() as $field) {
            $this->applyMandatoryFieldFallbackForField($field, $data, $displayData, $warnings, $prompt, $siteHandle);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     * @param  array<string, string>  $warnings
     */
    private function applyMandatoryFieldFallbackForField(Field $field, array &$data, array &$displayData, array &$warnings, string $prompt, string $siteHandle): void
    {
        $type = $field->type();
        $handle = $field->handle();

        if ($type === 'section' || in_array($type, FieldTypes::SKIP_TYPES, true)) {
            return;
        }

        if (in_array($type, FieldTypes::GROUP_TYPES, true)) {
            $this->applyToGroup($field, $data, $displayData, $warnings, $prompt, $siteHandle);

            return;
        }

        if (! $field->isRequired() || ! $this->generatableFieldMissingOrEmpty($field, $data, $handle)) {
            return;
        }

        $fallback = $this->fallbackFor($field, $warnings, $prompt, $siteHandle);

        if ($fallback === null) {
            $this->warnLeftEmpty($field, $warnings);

            return;
        }

        [$data[$handle], $displayData[$handle]] = $fallback;
        $this->requiredWasAutofilledWarning($field, $warnings);
    }

    /**
     * A group's sub-fields get their fallbacks too; a required group the model
     * left out entirely starts empty first.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     * @param  array<string, string>  $warnings
     */
    private function applyToGroup(Field $field, array &$data, array &$displayData, array &$warnings, string $prompt, string $siteHandle): void
    {
        $handle = $field->handle();

        if (! isset($data[$handle]) || ! is_array($data[$handle])) {
            if (! $field->isRequired()) {
                return;
            }
            $data[$handle] = [];
            $displayData[$handle] = [];
        }

        foreach ($field->fieldtype()->fields()->all() as $sub) {
            $this->applyMandatoryFieldFallbackForField($sub, $data[$handle], $displayData[$handle], $warnings, $prompt, $siteHandle);
        }
    }

    /**
     * The stored and display value for an empty required field, or null when
     * this fieldtype has no safe default (or building it failed).
     *
     * @param  array<string, string>  $warnings
     * @return array{0: mixed, 1: mixed}|null
     */
    private function fallbackFor(Field $field, array &$warnings, string $prompt, string $siteHandle): ?array
    {
        $type = $field->type();
        $both = fn (mixed $value): ?array => $value === null ? null : [$value, $value];

        if (in_array($type, FieldTypes::HTML_TYPES, true)) {
            $html = '<p>'.e($this->syntheticTextForRequiredField($field, $prompt)).'</p>';
            $mapped = $this->mapper->mapFieldValue($html, $field, $warnings, $siteHandle);

            return $mapped === null ? null : [$mapped, $html];
        }

        if (in_array($type, FieldTypes::CHOICE_TYPES, true)) {
            $first = $this->options->firstSelectOptionValue($field);

            if ($first === null || $first === '') {
                return null;
            }

            return $both($this->options->choiceFieldIsMultiple($field) ? [$first] : $first);
        }

        return match (true) {
            in_array($type, FieldTypes::TEXT_TYPES, true) => $both(strip_tags($this->aiService->cleanResult($this->syntheticTextForRequiredField($field, $prompt)))),
            $type === 'terms' => $both($this->references->firstTermValueForTermsField($field, $siteHandle)),
            in_array($type, FieldTypes::BOOLEAN_TYPES, true) => $both(false),
            in_array($type, FieldTypes::DATE_TYPES, true) => $both(now()->format('Y-m-d')),
            in_array($type, FieldTypes::LINK_TYPES, true) => $both($this->mapper->mapFieldValue('/', $field, $warnings, $siteHandle)),
            default => null,
        };
    }

    /**
     * Fieldtypes the agent cannot fill safely tell the editor what is missing.
     *
     * @param  array<string, string>  $warnings
     */
    private function warnLeftEmpty(Field $field, array &$warnings): void
    {
        $type = $field->type();

        $message = match (true) {
            $type === 'video' => ':field is required but no video URL was provided. Add a URL in the editor after saving.',
            $type === 'entries' => ':field is required but no matching existing entry was found to link. Set it in the editor after saving.',
            $type === 'form' => ':field is required but no matching form was found to link. Set it in the editor after saving.',
            in_array($type, FieldTypes::RECURSIVE_TYPES, true) => ':field is required but has no blocks. Add content in the editor after saving.',
            default => null,
        };

        if ($message !== null) {
            $warnings[] = __($message, ['field' => $field->display()]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function generatableFieldMissingOrEmpty(Field $field, array $data, string $handle): bool
    {
        if (! array_key_exists($handle, $data)) {
            return true;
        }

        $value = $data[$handle];
        $type = $field->type();

        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (in_array($type, FieldTypes::TEXT_TYPES, true) && is_string($value) && trim($value) === '') {
            return true;
        }

        if (in_array($type, FieldTypes::HTML_TYPES, true)) {
            return $this->isBardStoredValueEmpty($value);
        }

        if (in_array($type, FieldTypes::RECURSIVE_TYPES, true)) {
            return ! is_array($value) || $value === [];
        }

        if ($type === 'terms') {
            if ($value === null || $value === [] || $value === '') {
                return true;
            }

            if (is_string($value) && trim($value) === '') {
                return true;
            }

            return false;
        }

        return false;
    }

    private function isBardStoredValueEmpty(mixed $value): bool
    {
        if (! is_array($value)) {
            return true;
        }

        if (($value['type'] ?? '') === 'doc') {
            $content = $value['content'] ?? [];

            return $content === [] || $content === null;
        }

        return false;
    }

    private function syntheticTextForRequiredField(Field $field, string $prompt): string
    {
        $p = trim($prompt);

        if ($p === '' || preg_match('/^(do nothing|nothing|nichts|mach nichts|tu rien|ne rien faire)\.?$/iu', $p)) {
            $label = $field->display();

            return $label !== '' ? $label : (string) __('Untitled entry');
        }

        $limit = $field->handle() === 'title' ? 100 : 240;

        return Str::limit($p, $limit);
    }

    /**
     * @param  array<string, string>  $warnings
     */
    private function requiredWasAutofilledWarning(Field $field, array &$warnings): void
    {
        $warnings[] = __(':field was missing or empty but is required; a safe default was applied.', [
            'field' => $field->display(),
        ]);
    }
}

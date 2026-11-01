<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\BardButtonSanitizer;
use MotionWork\StatamicAiAssistant\Support\BardHtmlParser;
use Statamic\Fields\Field;

/**
 * The model's value for a Bard field: HTML, or ProseMirror nodes (the update
 * snapshot shows Bard that way), trimmed to the field's buttons.
 */
final class BardValueMapper
{
    public function __construct(private ScalarValueMapper $scalars) {}

    /**
     * Coerce a model-supplied Bard value into a flat list of ProseMirror block
     * nodes. Accepts a `doc` wrapper, a single node, or an already-flat list.
     *
     * @param  array<int|string, mixed>  $value
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProseMirrorNodes(array $value): array
    {
        // Unwrap a { type: "doc", content: [...] } document.
        if (($value['type'] ?? null) === 'doc' && is_array($value['content'] ?? null)) {
            $value = $value['content'];
        }

        // A single node object → wrap into a list.
        if (is_string($value['type'] ?? null)) {
            $value = [$value];
        }

        $nodes = [];
        foreach ($value as $node) {
            if (is_array($node) && is_string($node['type'] ?? null)) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    public function mapBardValue(mixed $value, Field $field, array &$warnings): ?array
    {
        $buttons = $field->config()['buttons'] ?? null;
        $buttons = is_array($buttons) ? $buttons : null;

        // In UPDATE mode the current-values snapshot shows Bard content as raw
        // ProseMirror JSON, so the model often returns the new value the same
        // way (an array of nodes) rather than as an HTML string. Accept that
        // directly instead of silently dropping the whole field.
        if (is_array($value)) {
            $nodes = $this->normalizeProseMirrorNodes($value);

            if ($nodes === []) {
                $this->scalars->warnNonScalarDrop($field, $warnings);

                return null;
            }

            return (new BardButtonSanitizer)->sanitizeBardNodesForFieldButtons($nodes, $buttons);
        }

        if (! is_scalar($value)) {
            $this->scalars->warnNonScalarDrop($field, $warnings);

            return null;
        }
        $html = is_string($value) ? $value : (string) $value;

        $nodes = (new BardHtmlParser)->htmlToFullBardDocument($html);

        return (new BardButtonSanitizer)->sanitizeBardNodesForFieldButtons($nodes, $buttons);
    }
}

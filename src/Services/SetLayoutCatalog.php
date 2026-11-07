<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * What each replicator/components set is for: title, editor hints and the mix
 * of content it holds, so the model picks blocks by purpose.
 */
final class SetLayoutCatalog
{
    public function __construct(private readonly SetHintsService $setHints) {}

    /**
     * Human-oriented summary of each replicator / components set for the LLM.
     *
     * @return array<int, array{type_handle: string, title: string, content_mix: string}>
     */
    public function buildSetLayoutCatalog(Field $field): array
    {
        if (! in_array($field->type(), ['replicator', 'components'], true)) {
            return [];
        }

        $fieldtype = $field->fieldtype();
        $catalog = [];

        foreach ($fieldtype->flattenedSetsConfig() as $setHandle => $setConfig) {
            $title = is_array($setConfig) && isset($setConfig['display'])
                ? (string) $setConfig['display']
                : Str::headline(str_replace('_', ' ', (string) $setHandle));

            $setFields = $fieldtype->fields($setHandle);

            $entry = [
                'type_handle' => (string) $setHandle,
                'title' => $title,
                'content_mix' => $this->contentMix($setFields),
            ];

            $hint = $this->setHints->forSet((string) $setHandle);

            if ($hint !== null) {
                if (($hint['ai_description'] ?? '') !== '') {
                    $entry['ai_description'] = $hint['ai_description'];
                }

                if (! empty($hint['when_to_use'])) {
                    $entry['when_to_use'] = array_values($hint['when_to_use']);
                }
            }

            $catalog[] = $entry;
        }

        return $catalog;
    }

    /**
     * Short phrase describing dominant field kinds in a set (guides layout variety).
     */
    public function contentMix(Fields $setFields): string
    {
        $tags = [];

        foreach ($setFields->all() as $f) {
            $t = $f->type();

            if ($t === 'assets') {
                $tags['images'] = 'images / visual';
            } elseif (in_array($t, FieldTypes::HTML_TYPES, true) || $t === 'markdown') {
                $tags['html'] = 'rich text';
            } elseif (in_array($t, FieldTypes::TEXT_TYPES, true)) {
                $tags['text'] = 'short text';
            } elseif (in_array($t, FieldTypes::LINK_TYPES, true)) {
                $tags['link'] = 'links';
            } elseif ($t === 'video') {
                $tags['video'] = 'video';
            } elseif (in_array($t, FieldTypes::CHOICE_TYPES, true) || in_array($t, FieldTypes::BOOLEAN_TYPES, true)) {
                $tags['control'] = 'choices / toggles';
            } elseif ($t === 'terms') {
                $tags['taxonomy'] = 'taxonomy / categories';
            } elseif (in_array($t, FieldTypes::RECURSIVE_TYPES, true)) {
                $tags['nested'] = 'nested layout';
            } elseif ($t === 'group') {
                $inner = $this->contentMix($f->fieldtype()->fields());
                if ($inner !== '') {
                    $tags['group_'.$f->handle()] = $inner;
                }
            }
        }

        $parts = array_values(array_unique(array_filter(array_values($tags))));

        return $parts !== [] ? implode(', ', $parts) : 'layout block';
    }
}

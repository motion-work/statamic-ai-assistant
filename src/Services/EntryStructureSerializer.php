<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Fields\Blueprint;
use Illuminate\Support\Str;

/**
 * Serialises an entry's stored values into a compact, size-capped text block for
 * the LLM. Complex fields (replicator/components/grid/group/bard) are emitted as
 * raw JSON so their set types, order and config are visible; scalars are inlined;
 * long strings are truncated; assets show their stored path(s).
 *
 * Single source of truth for both callers: the "edit this entry" update flow and
 * the read_entry_structure tool. The purpose-specific instructions (return the
 * complete new value / mirror this layout) live in the respective prompts, so the
 * serializer only renders neutral structure.
 *
 * Fields are classified by VALUE SHAPE (array/list vs object, string, scalar) plus
 * a couple of field-type names — deliberately independent of the generator's
 * GEN_* groupings, since "how to render for display" is a separate concern from
 * "how to generate".
 */
class EntryStructureSerializer
{
    private const TOTAL_CAP = 16000;

    private const PER_FIELD_CAP = 6000;

    private const TEXT_CAP = 1200;

    /**
     * @param  array<int, string>|null  $incompleteFields  Out-list: handles whose value
     *                                                     was truncated or omitted (the LLM never saw the full
     *                                                     content). Callers that let the LLM REPLACE such fields
     *                                                     wholesale should treat that as a data-loss risk.
     */
    public function serialize(StatamicEntry $entry, Blueprint $blueprint, ?array &$incompleteFields = null): string
    {
        $raw = is_array($entry->data()->all()) ? $entry->data()->all() : [];

        $usedTotal = 0;
        $lines = [];
        $incompleteFields = [];

        $omit = ' — omitted (exceeds size cap); the field has content';

        $append = function (string $line) use (&$lines, &$usedTotal): void {
            $usedTotal += strlen($line);
            $lines[] = $line;
        };

        $encode = function ($value, int $cap) use (&$usedTotal): ?string {
            if ($usedTotal >= self::TOTAL_CAP) {
                return null;
            }
            $json = json_encode($value, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return null;
            }
            if (strlen($json) > $cap) {
                $json = substr($json, 0, $cap).'  /* truncated */';
            }

            return $json;
        };

        foreach ($blueprint->fields()->all() as $field) {
            $handle = $field->handle();
            if (! array_key_exists($handle, $raw)) {
                continue;
            }

            $type = $field->type();
            if ($type === 'section' || $type === 'color') {
                continue;
            }

            $value = $raw[$handle];

            if ($type === 'assets') {
                $append(($value === null || $value === [] || $value === '')
                    ? "{$handle}: (no asset set)"
                    : "{$handle}: ".json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                continue;
            }

            if (is_string($value)) {
                $append("{$handle}: ".json_encode(Str::limit($value, self::TEXT_CAP), JSON_UNESCAPED_UNICODE));

                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $append("{$handle}: ".json_encode($value, JSON_UNESCAPED_UNICODE));

                continue;
            }

            if ($value === null) {
                $append("{$handle}: null");

                continue;
            }

            if (is_array($value)) {
                $isList = array_is_list($value);
                $label = $isList
                    ? 'list — '.count($value).' item(s)'
                    : 'object';
                $encoded = $encode($value, self::PER_FIELD_CAP);
                if ($encoded === null || str_ends_with($encoded, '/* truncated */')) {
                    $incompleteFields[] = $handle;
                }
                $append($encoded === null
                    ? "{$handle}: ({$label}{$omit})"
                    : "{$handle}: {$encoded}");
                $usedTotal += $encoded === null ? 0 : strlen($encoded);

                continue;
            }

            $encoded = $encode($value, self::PER_FIELD_CAP);
            if ($encoded === null || str_ends_with($encoded, '/* truncated */')) {
                $incompleteFields[] = $handle;
            }
            $append($encoded === null
                ? "{$handle}: (value omitted{$omit})"
                : "{$handle}: {$encoded}");
            $usedTotal += $encoded === null ? 0 : strlen($encoded);
        }

        return implode("\n", $lines);
    }
}

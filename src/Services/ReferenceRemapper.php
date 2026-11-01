<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\TranslationValueShapes;
use Statamic\Facades\Entry;

/**
 * Points a localization's entry references (entries fields, Bard links,
 * references inside sets) at the linked entries' localizations, translating
 * those too within the depth budget.
 */
final class ReferenceRemapper
{
    private const REFERENCE_TYPES = ['entries', 'link'];

    public function __construct(private EntryReferenceResolver $referenceResolver) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>  $fields
     * @return array<string, mixed>
     */
    public function resolveReferences(array $data, array $fields, string $targetSite, int $currentDepth, int $maxDepth): array
    {
        foreach ($data as $handle => &$value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $fieldDef = $fields[$handle] ?? null;
            $fieldType = $fieldDef['type'] ?? null;
            $isLocalizable = $fieldDef['localizable'] ?? true;

            if (in_array($fieldType, self::REFERENCE_TYPES)) {
                // Only resolve references for localizable fields — non-localizable
                // references are inherited from the origin and must not be remapped.
                if ($isLocalizable) {
                    $value = $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);
                }

                continue;
            }

            if (in_array($fieldType, FieldTextCollector::BARD_TYPES) && is_array($value)) {
                $value = $this->resolveBardEntryLinks($value, $targetSite, $currentDepth, $maxDepth);

                continue;
            }

            if (in_array($fieldType, FieldTextCollector::RECURSIVE_TYPES) && is_array($value)) {
                $value = $this->resolveReplicatorReferences($value, $fieldDef, $targetSite, $currentDepth, $maxDepth);

                continue;
            }
        }

        return $data;
    }

    /**
     * @param  array<mixed>  $nodes
     * @return array<mixed>
     */
    private function resolveBardEntryLinks(array $nodes, string $targetSite, int $currentDepth, int $maxDepth): array
    {
        foreach ($nodes as &$node) {
            if (! is_array($node)) {
                continue;
            }

            if (isset($node['marks']) && is_array($node['marks'])) {
                foreach ($node['marks'] as &$mark) {
                    if (($mark['type'] ?? null) === 'link' && isset($mark['attrs']['href'])) {
                        $mark['attrs']['href'] = (string) $this->referenceResolver->resolve(
                            $mark['attrs']['href'],
                            $targetSite,
                            $currentDepth,
                            $maxDepth,
                        );
                    }
                }
            }

            if (isset($node['content']) && is_array($node['content'])) {
                $node['content'] = $this->resolveBardEntryLinks($node['content'], $targetSite, $currentDepth, $maxDepth);
            }
        }

        return $nodes;
    }

    /**
     * @param  array<mixed>  $sets
     * @param  array{sets?: array, fields?: array}|null  $fieldDef
     * @return array<mixed>
     */
    private function resolveReplicatorReferences(array $sets, ?array $fieldDef, string $targetSite, int $currentDepth, int $maxDepth): array
    {
        $setsDefs = $fieldDef['sets'] ?? null;
        $gridFieldsDefs = $fieldDef['fields'] ?? null;

        foreach ($sets as &$set) {
            if (! is_array($set)) {
                continue;
            }

            $setType = $set['type'] ?? null;
            $subFields = null;

            if ($setsDefs !== null && $setType !== null) {
                $subFields = $setsDefs[$setType] ?? null;
            } elseif ($gridFieldsDefs !== null) {
                $subFields = $gridFieldsDefs;
            }

            foreach ($set as $key => &$value) {
                if (in_array($key, ['id', 'type', 'enabled']) || $value === null || $value === '' || $value === []) {
                    continue;
                }

                if ($subFields !== null) {
                    $subFieldType = $subFields[$key]['type'] ?? null;
                    $subFieldDef = $subFields[$key] ?? null;

                    if (in_array($subFieldType, self::REFERENCE_TYPES)) {
                        $value = $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);

                        continue;
                    }

                    if (in_array($subFieldType, FieldTextCollector::BARD_TYPES) && is_array($value)) {
                        $value = $this->resolveBardEntryLinks($value, $targetSite, $currentDepth, $maxDepth);

                        continue;
                    }

                    if (in_array($subFieldType, FieldTextCollector::RECURSIVE_TYPES) && is_array($value)) {
                        $value = $this->resolveReplicatorReferences($value, $subFieldDef, $targetSite, $currentDepth, $maxDepth);

                        continue;
                    }
                } else {
                    $value = $this->resolveReplicatorReferenceFallback($value, $targetSite, $currentDepth, $maxDepth);
                }
            }
        }

        return $sets;
    }

    private function resolveReplicatorReferenceFallback(mixed $value, string $targetSite, int $currentDepth, int $maxDepth): mixed
    {
        if (is_string($value) && (new TranslationValueShapes)->looksLikeEntryReference($value)) {
            return (string) $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);
        }

        if (is_array($value)) {
            if ((new TranslationValueShapes)->isBardContent($value)) {
                return $this->resolveBardEntryLinks($value, $targetSite, $currentDepth, $maxDepth);
            }

            if ((new TranslationValueShapes)->isUuidArray($value)) {
                return $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);
            }

            if ((new TranslationValueShapes)->isNestedSets($value)) {
                return $this->resolveReplicatorReferences($value, null, $targetSite, $currentDepth, $maxDepth);
            }
        }

        return $value;
    }
}

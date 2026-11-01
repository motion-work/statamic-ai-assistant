<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use MotionWork\StatamicAiAssistant\Services\EntryStructureSerializer;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

/**
 * The brief Claude composes an entry from: the content model (block catalog,
 * fields, rules) and, in a second step, the fields of the chosen blocks.
 */
final class ComposeBrief
{
    public function __construct(
        private ConnectorContext $context,
    ) {}

    /**
     * Without arguments, the collections the account can see. With a
     * collection (or an entry to refactor), the brief for composing one: the
     * agent's field schema with block hints, site instructions, glossary and
     * style rules, plus the entry's current content when refactoring.
     *
     * @return array<string, mixed>
     */
    public function contentModel(?string $collectionHandle, ?string $blueprintHandle, ?string $siteHandle, ?string $entryId): array
    {
        if ($entryId !== null && $entryId !== '') {
            $entry = $this->context->findEntry($entryId);
            if (is_array($entry)) {
                return $entry;
            }
            if (($denied = $this->context->refuse('view', $entry)) !== null) {
                return $denied;
            }
            $snapshot = app(EntryStructureSerializer::class)->serialize($entry, $entry->blueprint(), $incomplete, false);

            return array_merge($this->context->entrySummary($entry), [
                'blueprint' => $entry->blueprint()->handle(),
                'brief' => $this->context->generator()->composer->compositionBrief($entry->blueprint(), (string) $entry->locale(), $this->blockTypesIn($entry->data()->all())),
                'current_content' => $snapshot,
                'message' => 'current_content is the entry as stored, one field per line. Compose the refactored entry as one JSON object and pass it as data to preview_compose_entry with this entry_id. Fields you leave out stay as they are; a field you include replaces it whole, so include every block you want to keep. Refer to a block you keep by its id: send it back with its id (and any fields you change) and it is kept exactly as stored, including images and links you did not touch. Blocks without an id are new and follow the brief\'s format. Ask the user whether to change this entry in place or save the result as an unpublished copy, and pass mode accordingly.',
            ]);
        }

        if ($collectionHandle === null || $collectionHandle === '') {
            $collections = [];
            foreach (Collection::all() as $collection) {
                $blueprints = $collection->entryBlueprints()->reject->hidden()->map(fn ($b) => ['handle' => $b->handle(), 'title' => $b->title()])->values()->all();
                $sites = $collection->sites()->filter(fn ($site) => $this->context->actor() === null || $this->context->actor()->can('create', [Entry::class, $collection, Site::get($site)]))->values()->all();
                if ($blueprints === [] || $sites === []) {
                    continue;
                }
                $collections[] = ['handle' => $collection->handle(), 'title' => $collection->title(), 'blueprints' => $blueprints, 'sites' => $sites];
            }

            return [
                'collections' => $collections,
                'message' => 'Call get_content_model again with a collection (and optionally blueprint and site) for the brief to compose an entry.',
            ];
        }

        $resolved = $this->context->resolveTarget($collectionHandle, $blueprintHandle, $siteHandle);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        return [
            'collection' => $resolved['collection']->handle(),
            'blueprint' => $resolved['blueprint']->handle(),
            'site' => $resolved['site']->handle(),
            'brief' => $this->context->generator()->composer->compositionBrief($resolved['blueprint'], $resolved['site']->handle()),
            'message' => 'Choose the blocks from set_layout_catalog by their description and when-to-use rules, call get_block_schemas for their fields, then compose the entry as one JSON object in the brief\'s format from the user\'s goal and content, and pass it as data to preview_compose_entry. Follow the site instructions, glossary and style rules in the brief.',
        ];
    }

    /**
     * The fields of the blocks chosen from the catalog, for a collection or
     * for the entry being refactored.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<string, mixed>
     */
    public function blockSchemas(?string $collectionHandle, ?string $blueprintHandle, ?string $siteHandle, ?string $entryId, array $blocks): array
    {
        $handles = array_values(array_unique(array_filter(array_map('strval', $blocks))));
        if ($handles === []) {
            return ['error' => 'missing_blocks', 'message' => 'Pass the handles of the blocks you chose from set_layout_catalog.'];
        }

        if ($entryId !== null && $entryId !== '') {
            $entry = $this->context->findEntry($entryId);
            if (is_array($entry)) {
                return $entry;
            }
            if (($denied = $this->context->refuse('view', $entry)) !== null) {
                return $denied;
            }
            $blueprint = $entry->blueprint();
            $site = (string) $entry->locale();
        } else {
            $resolved = $this->context->resolveTarget((string) $collectionHandle, $blueprintHandle, $siteHandle);
            if (isset($resolved['error'])) {
                return $resolved;
            }
            $blueprint = $resolved['blueprint'];
            $site = $resolved['site']->handle();
        }

        return $this->context->generator()->composer->blockSchemas($blueprint, $site, $handles);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function blockTypesIn(array $data): array
    {
        $types = [];
        foreach ($data as $value) {
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    if (is_array($item) && is_string($item['type'] ?? null)) {
                        $types[] = $item['type'];
                    }
                }
            }
        }

        return array_values(array_unique($types));
    }
}

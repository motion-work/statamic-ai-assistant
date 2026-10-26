<?php

namespace BoldWeb\StatamicAiAssistant\Tools;

use BoldWeb\StatamicAiAssistant\Services\EntryStructureSerializer;
use Statamic\Facades\Entry;

/**
 * Lets the agent read an existing entry's real layout/components so it can create
 * a new entry that mirrors them ("create X based on entry Y's layout").
 *
 * Cross-blueprint safe by design: the result is labelled a reference from a
 * possibly different blueprint, and instructs the model to map each section onto
 * a set/field that exists in the TARGET schema it was given rather than copy
 * handles verbatim. If the model still emits a set type the target lacks, the
 * downstream mapper (EntryGeneratorService::mapReplicatorData) skips it with a
 * warning — so this never produces invalid data or crashes.
 */
class ReadEntryStructureTool implements ChatTool
{
    /**
     * @param  (callable(?string, string, int): array<int, array{id: string, title: string, slug: string, collection: string}>)  $entryFinder
     *         Resolves a title/slug query to entry rows (reuses the generator's shortlist search).
     */
    public function __construct(
        private EntryStructureSerializer $serializer,
        private $entryFinder,
    ) {}

    public function name(): string
    {
        return 'read_entry_structure';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'read_entry_structure',
                'description' => 'Read an EXISTING entry\'s layout and components (its sets/blocks in order, their fields and text) '
                    .'so you can create or update another entry that mirrors that structure. '
                    .'Provide entry_id when you know it (e.g. from find_entries), otherwise provide a title/slug query. '
                    .'The referenced entry may use a different blueprint — treat the result as a layout template, not literal handles to copy.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'entry_id' => [
                            'type' => 'string',
                            'description' => 'Statamic id of the entry to read (preferred, unambiguous).',
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Title or slug substring to locate the entry when the id is unknown.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        $entryId = isset($args['entry_id']) && is_string($args['entry_id']) ? trim($args['entry_id']) : '';
        $query = isset($args['query']) && is_string($args['query']) ? trim($args['query']) : '';

        if ($query !== '') {
            $context->reportActivity((string) __('Looking up entry ":query"', ['query' => $query]));
        }

        if ($entryId === '' && $query !== '') {
            $matches = ($this->entryFinder)(null, $query, 5);

            if ($matches === []) {
                return ['ok' => false, 'error' => 'entry_not_found', 'query' => $query];
            }

            if (count($matches) > 1) {
                // Ambiguous — let the model pick an id and retry.
                return ['ok' => false, 'error' => 'multiple_matches', 'matches' => $matches];
            }

            $entryId = (string) $matches[0]['id'];
        }

        if ($entryId === '') {
            return ['ok' => false, 'error' => 'entry_id_or_query_required'];
        }

        $entry = Entry::find($entryId);
        if (! $entry) {
            return ['ok' => false, 'error' => 'entry_not_found', 'entry_id' => $entryId];
        }

        $blueprint = $entry->blueprint();
        if (! $blueprint) {
            return ['ok' => false, 'error' => 'entry_has_no_blueprint', 'entry_id' => $entryId];
        }

        $context->reportActivity((string) __('Reading layout of :title', [
            'title' => \Illuminate\Support\Str::limit((string) ($entry->value('title') ?? $entryId), 50),
        ]));

        return [
            'ok' => true,
            'entry_id' => (string) $entry->id(),
            'title' => (string) ($entry->value('title') ?? ''),
            'collection' => (string) $entry->collectionHandle(),
            'blueprint' => (string) $blueprint->handle(),
            'note' => 'Reference only — this entry may use a DIFFERENT blueprint. Mirror the sequence and kinds of sections, '
                .'but map each onto a set/field that exists in the target entry schema you were given. Never invent set handles; '
                .'if the target has no equivalent, choose the closest or omit that section.',
            'structure' => $this->serializer->serialize($entry, $blueprint),
        ];
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_read_entries', 20));
    }
}

<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use Illuminate\Support\Str;
use Statamic\Facades\Entry;

/**
 * Lets the agent read an existing entry's FULL raw field values — unlike
 * read_entry_structure, which returns a truncated layout template. This is
 * what makes "answer a question about entry X", "copy the exact value from
 * entry Y" and precise update briefs possible.
 *
 * Payload discipline: entries with deep replicator trees can be huge, so when
 * the serialized data would exceed the size budget the tool returns a field
 * index (handle → approximate JSON size) and asks the model to re-request
 * specific handles via `fields` — the same "too large → filter" contract the
 * cboxdk/statamic-mcp addon uses for its paginated responses.
 */
class ReadEntryTool implements ChatTool
{
    /** Budget for the JSON-encoded `data` payload of one response. */
    private const MAX_DATA_BYTES = 60_000;

    /**
     * @param  (callable(?string, string, int): array<int, array<string, mixed>>)  $entryFinder
     *                                                                                           Resolves a title/slug query to entry rows (reuses the generator's shortlist search).
     */
    public function __construct(private $entryFinder) {}

    public function name(): string
    {
        return 'read_entry';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'read_entry',
                'description' => 'Read the FULL current field values of an EXISTING entry (raw values, not a summary). '
                    .'Use it to answer questions about an entry\'s content, copy exact values, or inspect an entry before describing an update. '
                    .'Provide entry_id when you know it (e.g. from find_entries), otherwise provide a title/slug query. '
                    .'If the entry is too large the response lists the available field handles with their sizes — repeat the call with "fields" naming only the handles you need. '
                    .'To mirror an entry\'s LAYOUT into a possibly different blueprint, use read_entry_structure instead.',
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
                        'fields' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional field handles to return. Omit for all fields (large entries may then ask you to narrow down).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        $args = ToolArguments::decode($argumentsJson, $this->name());
        if ($args === null) {
            return ToolArguments::invalid();
        }

        $entry = $this->requestedEntry($args);
        if (is_array($entry)) {
            return $entry;
        }

        $context->reportActivity((string) __('Reading entry :title', [
            'title' => Str::limit((string) ($entry->value('title') ?? $entry->id()), 50),
        ]));

        $meta = $this->entryMeta($entry);
        [$data, $missing] = $this->requestedFields($entry->data()->all(), $args['fields'] ?? null);

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded !== false && strlen($encoded) > self::MAX_DATA_BYTES) {
            return [
                'ok' => false,
                'error' => 'entry_too_large',
                'entry_id' => $meta['entry_id'],
                'title' => $meta['title'],
                'available_fields' => $this->fieldSizes($data),
                'hint' => 'Repeat the call with "fields": [...] naming only the handles you need (sizes above are approximate JSON bytes).',
            ];
        }

        $result = ['ok' => true] + $meta + ['data' => $data];

        if ($missing !== []) {
            $result['missing_fields'] = $missing;
        }

        return $result;
    }

    /**
     * The entry named by entry_id, or by a query matching exactly one entry;
     * otherwise the error result.
     *
     * @param  array<string, mixed>  $args
     * @return \Statamic\Contracts\Entries\Entry|array<string, mixed>
     */
    private function requestedEntry(array $args): mixed
    {
        $entryId = isset($args['entry_id']) && is_string($args['entry_id']) ? trim($args['entry_id']) : '';
        $query = isset($args['query']) && is_string($args['query']) ? trim($args['query']) : '';

        if ($entryId === '' && $query !== '') {
            $found = $this->findByQuery($query);
            if (is_array($found)) {
                return $found;
            }
            $entryId = $found;
        }

        if ($entryId === '') {
            return ['ok' => false, 'error' => 'entry_id_or_query_required'];
        }

        return Entry::find($entryId) ?? ['ok' => false, 'error' => 'entry_not_found', 'entry_id' => $entryId];
    }

    /**
     * Only the requested handles (all when none are named), plus the named
     * handles the entry has no value for.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function requestedFields(array $data, mixed $fields): array
    {
        $wanted = is_array($fields)
            ? array_values(array_filter($fields, fn ($f) => is_string($f) && trim($f) !== ''))
            : [];

        if ($wanted === []) {
            return [$data, []];
        }

        return [array_intersect_key($data, array_flip($wanted)), array_values(array_diff($wanted, array_keys($data)))];
    }

    /**
     * The id of the one entry matching $query, or the error result when none
     * or several match (the model then picks an id and retries).
     *
     * @return string|array<string, mixed>
     */
    private function findByQuery(string $query): string|array
    {
        $matches = ($this->entryFinder)(null, $query, 5);

        if ($matches === []) {
            return ['ok' => false, 'error' => 'entry_not_found', 'query' => $query];
        }

        if (count($matches) > 1) {
            return ['ok' => false, 'error' => 'multiple_matches', 'matches' => $matches];
        }

        return (string) $matches[0]['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryMeta($entry): array
    {
        $meta = [
            'entry_id' => (string) $entry->id(),
            'title' => (string) ($entry->value('title') ?? ''),
            'slug' => (string) ($entry->slug() ?? ''),
            'collection' => (string) $entry->collectionHandle(),
            'blueprint' => (string) ($entry->blueprint()?->handle() ?? ''),
            'site' => (string) ($entry->locale() ?? ''),
            'published' => (bool) $entry->published(),
            'url' => (string) ($entry->url() ?? ''),
        ];

        if ($entry->collection()?->dated()) {
            $meta['date'] = (string) ($entry->date()?->toDateString() ?? '');
        }

        if ($entry->collection()?->hasStructure()) {
            // Its place in the page tree (null: top level); move_entry changes it.
            $parent = $entry->structure()->in($entry->locale())->find($entry->id())?->parent();
            $meta['parent_id'] = $parent && ! $parent->isRoot() ? (string) $parent->id() : null;
        }

        return $meta;
    }

    /**
     * Approximate JSON size per field, largest first, so the model can
     * re-request just the handles it needs when the entry is too large.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    private function fieldSizes(array $data): array
    {
        $sizes = [];
        foreach ($data as $handle => $value) {
            $enc = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $sizes[$handle] = $enc === false ? 0 : strlen($enc);
        }
        arsort($sizes);

        return $sizes;
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_read_entries', 20));
    }
}

<?php

namespace MotionWork\StatamicAiAssistant\Tools;

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
     *         Resolves a title/slug query to entry rows (reuses the generator's shortlist search).
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

        $context->reportActivity((string) __('Reading entry :title', [
            'title' => \Illuminate\Support\Str::limit((string) ($entry->value('title') ?? $entryId), 50),
        ]));

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

        $data = $entry->data()->all();

        $wanted = isset($args['fields']) && is_array($args['fields'])
            ? array_values(array_filter($args['fields'], fn ($f) => is_string($f) && trim($f) !== ''))
            : [];

        $missing = [];
        if ($wanted !== []) {
            $missing = array_values(array_diff($wanted, array_keys($data)));
            $data = array_intersect_key($data, array_flip($wanted));
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded !== false && strlen($encoded) > self::MAX_DATA_BYTES) {
            // Too big to return whole: hand back a size-sorted field index so the
            // model can immediately re-request just the handles it needs.
            $index = [];
            foreach ($data as $handle => $value) {
                $enc = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $index[$handle] = $enc === false ? 0 : strlen($enc);
            }
            arsort($index);

            return [
                'ok' => false,
                'error' => 'entry_too_large',
                'entry_id' => $meta['entry_id'],
                'title' => $meta['title'],
                'available_fields' => $index,
                'hint' => 'Repeat the call with "fields": [...] naming only the handles you need (sizes above are approximate JSON bytes).',
            ];
        }

        $result = ['ok' => true] + $meta + ['data' => $data];

        if ($missing !== []) {
            $result['missing_fields'] = $missing;
        }

        return $result;
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_read_entries', 20));
    }
}

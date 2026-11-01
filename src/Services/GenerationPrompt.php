<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * The agent's generation prompt: the system message with the encoded field
 * schema, the rules the schema calls for (grids, terms, dates, language) and
 * the editors' site instructions; and the user message with its sources.
 */
final class GenerationPrompt
{
    /**
     * Internal schema keys that are noise for the model — present for our own
     * bookkeeping, never referenced in the prompt rules and never read back.
     * This is a DENYLIST: only these keys are dropped, so any other key (now or
     * added in future) is preserved by default and can never be silently lost.
     */
    private const SCHEMA_NOISE_KEYS = ['generatable'];

    public function __construct(
        private EditorialGuidanceService $editorialGuidance,
        private SetHintsService $setHints,
    ) {}

    /**
     * A field description used more than once (the HTML rule, grid rows, an
     * editor's anchor-id hint …) becomes one numbered note, and each field
     * says "see note N" instead. Nothing is dropped.
     *
     * @param  array<int|string, mixed>  $schema
     * @return array{0: array<int|string, mixed>, 1: array<int, string>}
     */
    public function hoistRepeatedDescriptions(array $schema): array
    {
        $counts = [];
        array_walk_recursive($schema, function ($value, $key) use (&$counts) {
            if ($key === 'description' && is_string($value) && mb_strlen($value) >= 30) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        });

        $notes = [];
        foreach ($counts as $text => $count) {
            if ($count > 1) {
                $notes[count($notes) + 1] = $text;
            }
        }
        if ($notes === []) {
            return [$schema, []];
        }

        $numbers = array_flip($notes);
        $replace = function (array $node) use (&$replace, $numbers): array {
            foreach ($node as $key => $value) {
                if ($key === 'description' && is_string($value) && isset($numbers[$value])) {
                    $node[$key] = 'See note '.$numbers[$value].'.';
                } elseif (is_array($value)) {
                    $node[$key] = $replace($value);
                }
            }

            return $node;
        };

        return [$replace($schema), $notes];
    }

    /**
     * @param  array<int, string>  $notes
     */
    public function fieldNotesBlock(array $notes): string
    {
        if ($notes === []) {
            return '';
        }

        $lines = ["\n\nFIELD NOTES (a field description \"See note N.\" means this text):"];
        foreach ($notes as $number => $text) {
            $lines[] = $number.'. '.$text;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     */
    private function fieldSchemaContainsStructuredType(array $schema): bool
    {
        return $this->fieldSchemaContainsType($schema, 'structured');
    }

    private function fieldSchemaContainsTaxonomyTerms(array $schema): bool
    {
        return $this->fieldSchemaContainsType($schema, 'taxonomy_terms');
    }

    /**
     * Whether any field entry in the schema (or nested inside a group's
     * "fields", a replicator/components "sets", or a grid's "row_fields") has
     * the given schema type.
     *
     * @param  array<string, array<string, mixed>>  $schema
     */
    private function fieldSchemaContainsType(array $schema, string $type): bool
    {
        foreach ($schema as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['type'] ?? null) === $type) {
                return true;
            }

            foreach (['fields', 'row_fields'] as $nestedKey) {
                if (isset($entry[$nestedKey]) && is_array($entry[$nestedKey]) && $this->fieldSchemaContainsType($entry[$nestedKey], $type)) {
                    return true;
                }
            }

            if (isset($entry['sets']) && is_array($entry['sets'])) {
                foreach ($entry['sets'] as $setSchema) {
                    if (is_array($setSchema) && $this->fieldSchemaContainsType($setSchema, $type)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Encode the field schema for the prompt: strip internal-only noise keys and
     * emit minified JSON (no pretty-print indentation, unescaped slashes/unicode).
     * This only shrinks what we SEND — it never changes the field handles, types,
     * descriptions or constraints the model reads, nor how the response is parsed.
     */
    private function encodeSchemaForPrompt(array $fieldSchema): string
    {
        return (string) json_encode(
            $this->stripSchemaNoise($fieldSchema),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Recursively drop {@see SCHEMA_NOISE_KEYS} at every depth (top level, group
     * fields, grid row_fields, replicator sets, …). Everything else passes
     * through untouched.
     *
     * @param  array<int|string, mixed>  $schema
     * @return array<int|string, mixed>
     */
    public function stripSchemaNoise(array $schema): array
    {
        $clean = [];

        foreach ($schema as $key => $value) {
            if (is_string($key) && in_array($key, self::SCHEMA_NOISE_KEYS, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->stripSchemaNoise($value) : $value;
        }

        return $clean;
    }

    public function buildSystemMessage(array $fieldSchema, string $locale): string
    {
        $preface = config('statamic-ai-assistant.prompt_generator_preface',
            'You are a CMS content creation assistant. Generate structured content for website entries. Respond ONLY with a valid JSON object — no markdown fences, no commentary.'
        );

        $schemaJson = $this->encodeSchemaForPrompt($fieldSchema);

        $structuredRules = '';

        if ($this->fieldSchemaContainsTaxonomyTerms($fieldSchema)) {
            $structuredRules .= "\n"
                ."- For fields with type \"taxonomy_terms\": pick values only from the slugs listed under each taxonomy in \"taxonomies\". Output the exact slug (single-taxonomy fields) or \"taxonomy_handle::term_slug\" when \"single_taxonomy\" is false. You may infer the best choice from the user prompt.\n";
        }

        if ($this->fieldSchemaContainsStructuredType($fieldSchema)) {
            $structuredRules .= "\n"
                ."- For fields with type \"structured\" (replicator / components / grid): each array item is one block; include a \"type\" property with the set handle, then that set's field keys.\n"
                ."- Block selection: when set_layout_catalog is provided, treat it as the authoritative guide. For each block, read its \"ai_description\" (what the block is and how it renders) and its \"when_to_use\" list (the concrete scenarios it exists for). Pick a block ONLY when the section you are building matches one of its when_to_use triggers, or clearly fits its ai_description. If none match, pick the closest block by content_mix rather than force-fitting the most generic one.\n"
                ."- Treat when_to_use as strong editorial rules, not suggestions: if a block says it is for \"hero openers\", do not reuse it mid-page for unrelated content. If multiple blocks could fit, prefer the one whose when_to_use most specifically describes the section.\n"
                ."- Layout variety: deliberately mix several different type_handle values across the page. Do not default the whole page to one or two repetitive blocks (for example only plain text and teaser) when other sets with matching when_to_use / ai_description exist.\n"
                ."- Visual rhythm: include blocks whose ai_description, when_to_use, or content_mix mentions images, galleries, heroes, or visual layout when they support the narrative — not only text-heavy blocks.\n"
                ."- For type \"asset_description\" (inside sets): a concise phrase describing the desired image; empty string is allowed and imagery may be assigned automatically.\n";
        }

        if ($this->fieldSchemaContainsType($fieldSchema, 'grid')) {
            $structuredRules .= "\n"
                ."- For fields with type \"grid\": this is a REPEATING list/table, not a single rich-text block. Return an ARRAY OF ROWS. Each row is one discrete item — an object keyed by the handles under \"row_fields\", with NO \"type\" key.\n"
                ."- One item per row: when the field's source material is a list (bullet points, key/value pairs, dates, features, steps, FAQ entries), output ONE row per item. Do NOT pour the whole list into a single row's field, even when that row field is rich text / HTML.\n";
        }

        return $preface."\n\n"
            ."You MUST write all content in this language/locale: {$locale}\n\n"
            .$this->germanNoEszettInstructions($locale)
            ."Here is the field schema for the entry you need to create. The JSON keys in your response must exactly match the field handles (the keys below):\n\n"
            .$schemaJson."\n\n"
            ."Rules:\n"
            ."- Respond with ONLY a valid JSON object. No markdown code fences, no explanation.\n"
            ."- For fields with type \"text\": provide plain text only, no HTML.\n"
            ."- For fields with type \"html\": provide valid HTML using only: p, h2, h3, h4, ul, ol, li, a, strong, em, blockquote, br tags.\n"
            ."- For fields with type \"select\": choose one of the provided options.\n"
            ."- For fields with type \"boolean\": provide true or false.\n"
            .$this->statamicDateFieldRulesForPrompt()
            ."- For fields with type \"structured\": provide an array of objects, each with a \"type\" key matching a set handle, plus the set's field values.\n"
            ."- For fields with type \"grid\": provide an array of rows, each row an object keyed by the handles under \"row_fields\" (no \"type\" key). Each row is one item; make one row per discrete item.\n"
            ."- For fields with type \"group\": provide a JSON object whose keys match the nested field handles (see \"fields\" in the schema).\n"
            ."- For fields with type \"link\": provide a URL, path, or entry::UUID reference as described in the field description.\n"
            ."- For fields with type \"video_url\": provide a YouTube or video page URL, or an empty string.\n"
            ."- For fields with type \"taxonomy_terms\": use only slugs from the schema \"taxonomies\" lists (exact slug when \"single_taxonomy\" is true; otherwise taxonomy_handle::term_slug).\n"
            ."- For fields with type \"entry_references\": link ONLY entries that already exist, using their exact title (or slug). Use an array for multiple, a single string when the field allows one, and an empty array/string when none applies. If the entry you were asked to link does not exist yet (e.g. it is being created in this same request), LEAVE THIS FIELD EMPTY and still produce the rest of the entry normally — never refuse, abort, apologise, or demand a taxonomy just because a link target is missing.\n"
            ."- For fields with type \"form_reference\": pick a form ONLY from that field's \"forms\" list, outputting its exact handle (match the user's intent, e.g. a contact form). Never invent a handle. Single string when the field allows one, otherwise an array; empty when none applies.\n"
            .$structuredRules
            ."- Field-level editorial guidance: when a field in the schema carries \"ai_description\" (what the field is and how it renders on the page) and/or \"writing_guidelines\" (concrete authoring rules such as length, tone, or structure), treat them as binding editorial instructions for THAT field's content — they override generic assumptions you would otherwise make from the handle alone.\n"
            ."- Every field in the schema that includes \"required\": true must have a non-empty, valid value for its type. Never omit those keys, never use empty strings for them, and never use HTML with no visible text. If the user is vague, says to do nothing, or gives minimal instructions, you must still invent sensible placeholder content so the entry would pass blueprint validation.\n"
            ."- For fields without \"required\": true, if you cannot determine content, use an empty string when appropriate.\n"
            ."- Generate meaningful, high-quality content that is relevant to the user's request."
            .$this->siteInstructionsPromptBlock()
            .$this->editorialGuidance->promptBlock($locale);
    }

    /**
     * Editor-maintained site-wide instructions (AI instructions page), appended
     * to every generation prompt. Length-capped so a very long document cannot
     * crowd out the field schema.
     */
    private function siteInstructionsPromptBlock(): string
    {
        $instructions = $this->setHints->siteInstructions();

        if ($instructions === '') {
            return '';
        }

        return "\n\nSITE INSTRUCTIONS (set by this site's editors — always follow them):\n"
            .Str::limit($instructions, 4000);
    }

    /**
     * Global Rules bullet: Statamic Date fieldtype values are plain Y-m-d strings in JSON.
     */
    private function statamicDateFieldRulesForPrompt(): string
    {
        return '- For fields with type "date" (Statamic **date** fieldtype): output a **JSON string** in calendar form **YYYY-MM-DD** only '
            .'(four-digit year, two-digit month, two-digit day, ASCII hyphens; example: `"2026-04-28"`). '
            .'Statamic stores PHP `Y-m-d` strings, not datetimes: do **not** use `T`, time zones, `Z`, or `2026-04-28T00:00:00`. '
            .'Do **not** use Unix timestamps, bare numbers, `DD.MM.YYYY`, `MM/DD/YYYY`, written-out months, or relative phrases like "today". '
            ."If the user prompt or fetched page shows another format, convert it to `YYYY-MM-DD`.\n";
    }

    /**
     * Swiss-style German: never ß, always ss (project preference for generated copy).
     */
    private function germanNoEszettInstructions(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));

        if ($normalized === '' || ! str_starts_with($normalized, 'de')) {
            return '';
        }

        return "German orthography (mandatory for every German string you output, including titles and body text):\n"
            ."- NEVER use the letter ß (Eszett). Always use \"ss\" instead.\n"
            ."- Examples: \"Strasse\" not \"Straße\", \"gross\" not \"groß\", \"heiss\" not \"heiß\", \"dass\" stays \"dass\".\n\n";
    }

    public function buildUserMessage(string $prompt, ?string $attachmentContent, string $urlAppendix = ''): string
    {
        $message = $prompt;

        if ($urlAppendix !== '') {
            $message .= $urlAppendix;
        }

        if ($attachmentContent) {
            // The attached document IS the source material the user wants the
            // entry built from. Say so explicitly and forbid going online for it
            // — otherwise the model tends to fetch a URL it spots in the text and
            // build the entry from that instead of the file the user attached.
            $message = 'The user attached a document whose full content is included below under "--- ATTACHED DOCUMENT CONTENT ---". '
                .'Treat that content as your PRIMARY source for this entry: write the entry from its facts, structure, names, numbers and wording. '
                ."Do NOT fetch external URLs to source this entry, and do NOT invent content the attachment does not contain.\n\n"
                .$message;
            $message .= "\n\n--- ATTACHED DOCUMENT CONTENT ---\n\n".$attachmentContent;
        }

        return $message;
    }

    /**
     * Update-mode system prompt: same field schema rules as creation, but the
     * model is told to omit unchanged fields and is shown a snapshot of current values.
     *
     * @param  array<string, array<string, mixed>>  $fieldSchema
     * @param  array<string, mixed>  $currentSnapshot
     */
    public function buildUpdateSystemMessage(array $fieldSchema, string $locale, string $currentSnapshot): string
    {
        $base = $this->buildSystemMessage($fieldSchema, $locale);

        // Highest-priority block: this comes BEFORE the field-schema rules so
        // the LLM anchors on update semantics (omit-to-keep, full-replace,
        // path-picking) rather than the create-mode wording in $base.
        $priority = "\n\n========== UPDATE MODE — read this BEFORE the schema rules above ==========\n"
            ."You are editing an EXISTING entry. Different rules apply than creation:\n\n"
            ."1) OMIT KEYS YOU DO NOT CHANGE.\n"
            ."   - Return ONLY the keys you want to change. Keys you omit are kept as-is.\n"
            ."   - Do NOT echo unchanged fields back; do NOT invent placeholder values; do NOT re-fill required fields the user did not ask about.\n"
            ."   - Never use null or empty string to clear a field — omitting it is the only way to leave it unchanged.\n\n"
            ."2) ASSET FIELDS (type \"asset_description\" in the schema, with an `available_assets` array):\n"
            ."   - This rule OVERRIDES any earlier wording about asset_description being \"a concise phrase describing the desired image\".\n"
            ."   - To KEEP the current image: omit the key entirely.\n"
            ."   - To REPLACE the image: return ONE exact path string copied from that field's `available_assets` array — nothing else.\n"
            ."   - When the user excludes a folder/path (e.g. \"don't use anything from the set previews folder\"), simply SKIP every path in `available_assets` whose prefix matches that exclusion and pick from the remaining paths.\n"
            ."   - When the user asks for variety (\"use another image\", \"different from before\"), avoid the path that is currently set (shown in the snapshot below) and pick a DIFFERENT one from `available_assets`.\n"
            ."   - Only when no path in `available_assets` fits the user's intent: return a short free-form description instead, and the system picks a random fitting asset (this is the LAST resort and will NOT respect path-based exclusions).\n\n"
            ."3) COMPLEX FIELDS (Bard html, structured/replicator/components/grid, group):\n"
            ."   - The snapshot below shows their current FULL content as raw JSON.\n"
            ."   - If you change one, you MUST return the COMPLETE new value with your edits applied — what you return REPLACES the entire field. Returning a partial value will lose data.\n\n"
            ."4) The required-field rule from creation does NOT apply here — the entry already validates.\n"
            ."============================================================================\n";

        $tail = "\n\nCurrent values for this entry (for context — do not echo back unchanged):\n"
            .$currentSnapshot;

        return $priority.$base.$tail;
    }
}

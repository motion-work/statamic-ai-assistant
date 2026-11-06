<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\SiteLanguageResolver;

/**
 * The OpenAI-style definitions of the planner's own tools, sent after the read tools.
 */
final class PlannerJobToolDefinitions
{
    /**
     * Definitions of the job tools, sent after the read tools.
     *
     * @return array<int, array{type: string, function: array<string, mixed>}>
     */
    public function definitions(int $cap): array
    {
        return [
            $this->createEntryJobToolDefinition($cap),
            // The two tools below power the AI agent's UPDATE flow. They are
            // intentionally separate from create_entry_job — to drop update
            // support, remove these two tool defs + their handlers + the
            // dispatch branches below; create_entry_job is unaffected.
            $this->updateEntryJobToolDefinition($cap),
            $this->localizeEntryToolDefinition($cap),
            $this->findEntriesToolDefinition(),
            $this->searchEntryContentToolDefinition(),
            $this->answerQuestionToolDefinition(),
            $this->proposePlanToolDefinition(),
        ];
    }

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function createEntryJobToolDefinition(int $cap): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_entry_job',
                'description' => 'Queue ONE brand-new entry for asynchronous generation on the current site. Call this incrementally — once per distinct article/item the user asked for. The card appears in the user\'s drawer the moment this is called, and a worker starts generating its content in parallel. Cap: '.$cap.' calls per request. Do NOT use this to translate an existing entry or to "create the EN/FR/IT version" of a page that already exists — that is localize_entry.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'collection' => [
                            'type' => 'string',
                            'description' => 'Collection handle from the catalog (case-sensitive).',
                        ],
                        'blueprint' => [
                            'type' => 'string',
                            'description' => 'Blueprint handle, must be one of the blueprints listed for the chosen collection.',
                        ],
                        'label' => [
                            'type' => 'string',
                            'description' => 'Short 2-6 word title for the UI card, in the user\'s language.',
                        ],
                        'prompt' => [
                            'type' => 'string',
                            'description' => 'Self-contained brief for this single entry, in the user\'s language. Include the canonical detail URL when applicable so the worker can fetch the source.',
                        ],
                    ],
                    'required' => ['collection', 'blueprint', 'label', 'prompt'],
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function updateEntryJobToolDefinition(int $cap): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_entry_job',
                'description' => 'Queue an UPDATE to one existing entry. Use when the user wants to change, rewrite, fix, or extend an entry that already exists. Only for its FIELD values: moving an entry to another parent or position in a page tree is move_entry, never this. You must provide the entry_id (from the catalog shortlist or from find_entries). The collection and blueprint are inferred from the entry. Counts toward the same '.$cap.'-call cap as create_entry_job.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'entry_id' => [
                            'type' => 'string',
                            'description' => 'Statamic entry id of the existing entry to update.',
                        ],
                        'label' => [
                            'type' => 'string',
                            'description' => 'Short 2-6 word title for the UI card, in the user\'s language. Usually the existing entry\'s title or a hint of what is changing.',
                        ],
                        'prompt' => [
                            'type' => 'string',
                            'description' => 'Self-contained brief describing ONLY what should change on this entry, in the user\'s language. Do not restate parts of the entry the user did not ask to modify.',
                        ],
                    ],
                    'required' => ['entry_id', 'prompt'],
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function findEntriesToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'find_entries',
                'description' => 'Search existing entries by title or slug. Use when the catalog shortlist does not contain the entry the user is referring to. Matching is tolerant: case-insensitive, word-order independent, and connective words/symbols are ignored (so "Salt Stone", "Salt and Stone" and "Salt & Stone" all match the same entry). Returns up to `limit` rows, best matches first, with id/title/slug/collection/published, plus a `pagination` block — when `pagination.has_more` is true you have NOT seen every match; call again with a higher `offset` or a narrower query.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Words from the entry title or slug. Just pass the title as the user said it; punctuation, casing and word order do not need to match. Required.',
                        ],
                        'collection' => [
                            'type' => 'string',
                            'description' => 'Optional collection handle to scope the search. Omit to search all collections.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max rows to return (default 10, max 50).',
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => 'Rows to skip, for paging through more matches (default 0).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function searchEntryContentToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'search_entry_content',
                'description' => 'Search INSIDE the body/content of existing entries — not just their title/slug. Use this when the user references an entry by a phrase, sentence, name or value that appears in its content (e.g. "the entry that contains \'Kursleitung: Claudia Eva Reinig\'"). Matching is tolerant (case, punctuation and word order do not matter) and works even when the phrase is split across rich-text blocks. Returns up to `limit` rows with id/title/slug/collection and a short `snippet` showing the match. The entries\' full content is scanned on the server and is NOT returned — only snippets.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The words/phrase to look for inside entry content. Required.',
                        ],
                        'collection' => [
                            'type' => 'string',
                            'description' => 'Optional collection handle to scope (and speed up) the search. Omit to search all collections.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max rows to return (default 10, max 50).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function proposePlanToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'propose_plan',
                'description' => 'Propose a plan and PAUSE for the user\'s approval before acting. Use this FIRST for complex requests: 3 or more entries, structural changes combined with content, or when information you need is missing. Pass short imperative steps (one per intended action, in the user\'s language) and optionally ONE question when information is missing. Calling this ends the current run — the user approves or answers in their next message, then you execute. Do NOT use it for simple requests (one or two clear entries): act directly instead. Never call it again for a plan the user already approved.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'steps' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Short imperative steps in execution order, one per action (e.g. "Create collection projects", "Create blueprint with hero import", "Create 3 example entries").',
                        ],
                        'question' => [
                            'type' => 'string',
                            'description' => 'ONE question for the user when required information is missing. Omit when nothing is missing.',
                        ],
                        'approve_label' => [
                            'type' => 'string',
                            'description' => 'Label for the button the user clicks to go ahead, in their language, 2 to 5 words, saying what will happen (e.g. "3 Einträge erstellen", "Zuordnung übernehmen"). When you ask a question, phrase it as the yes-answer that also approves the plan (e.g. "Ja, diese FAQ zuordnen"). Clicking it sends this label as the user\'s reply.',
                        ],
                    ],
                    'required' => ['steps'],
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function answerQuestionToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'answer_question',
                'description' => 'Answer the user directly WITHOUT creating or updating anything. Use this only when the user is asking a read-only question (e.g. "which entry contains X?", "do we already have a page about Y?", "what is the id of Z?") rather than requesting a change. First look the answer up with find_entries / search_entry_content, then call this with a short, complete answer in the user\'s language. Calling this ends the run successfully.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'answer' => [
                            'type' => 'string',
                            'description' => 'The complete answer to show the user, in their language. Include the entry title and id when relevant. When asked about a document/attachment, put the ACTUAL content here — quote or summarise the document text itself. This text is displayed verbatim, so it must BE the answer, never a remark about answering or about your process (NOT "no action needed", "I will answer directly", "I have already answered", "using the provided document text", "no lookup is required").',
                        ],
                    ],
                    'required' => ['answer'],
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function localizeEntryToolDefinition(int $cap): array
    {
        $sites = implode('; ', (new SiteLanguageResolver)->catalog());

        return [
            'type' => 'function',
            'function' => [
                'name' => 'localize_entry',
                'description' => 'Localize ONE existing entry onto another site and translate its content there. The result is a Statamic localization of the source entry (origin linked), saved on the target site. It is not a new entry on the current site. Use this for "translate this page to English", "create the EN entry", "übersetze diese Seite". Counts toward the same '.$cap.'-call cap as create_entry_job. Sites: '.$sites.'.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'entry_id' => [
                            'type' => 'string',
                            'description' => 'Statamic id of the source entry (the page the user wants translated). From the @mention, the catalog shortlist, or find_entries.',
                        ],
                        'target_site' => [
                            'type' => 'string',
                            'description' => 'Target site: a handle (en), a locale (en-US), or a language name (English, Englisch). Must be a different site from the source entry.',
                        ],
                        'label' => [
                            'type' => 'string',
                            'description' => 'Short 2-6 word title for the UI card, in the user\'s language. Usually the source title plus the target language.',
                        ],
                    ],
                    'required' => ['entry_id', 'target_site'],
                ],
            ],
        ];
    }
}

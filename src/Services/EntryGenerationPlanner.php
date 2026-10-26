<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Support\CpFeatures;
use MotionWork\StatamicAiAssistant\Support\EntryCreationPolicy;
use MotionWork\StatamicAiAssistant\Support\JsonObjectExtractor;
use MotionWork\StatamicAiAssistant\Support\PlanEntryDecorator;
use MotionWork\StatamicAiAssistant\Support\SiteLanguageResolver;
use Statamic\Facades\Site;
use MotionWork\StatamicAiAssistant\Tools\Advanced\AdvancedToolset;
use MotionWork\StatamicAiAssistant\Tools\AnalyzeImageTool;
use MotionWork\StatamicAiAssistant\Tools\ChatToolRunner;
use MotionWork\StatamicAiAssistant\Tools\ListAssetsTool;
use MotionWork\StatamicAiAssistant\Tools\ListTaxonomiesTool;
use MotionWork\StatamicAiAssistant\Tools\ReadEntryStructureTool;
use MotionWork\StatamicAiAssistant\Tools\ReadEntryTool;
use MotionWork\StatamicAiAssistant\Tools\ReadGlobalsTool;
use MotionWork\StatamicAiAssistant\Tools\ReadNavTreeTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use MotionWork\StatamicAiAssistant\Tools\UpdateAssetTool;
use MotionWork\StatamicAiAssistant\Tools\UrlFetchTool;
use MotionWork\StatamicAiAssistant\Tools\UseAssetsTool;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Decompose a free-text user request into a list of independent entry plans.
 *
 * Each plan targets one collection + blueprint and carries a focused brief
 * so the existing single-entry generator can be reused per item.
 */
class EntryGenerationPlanner
{
    /** Max consecutive empty model responses to nudge through before giving up. */
    private const MAX_EMPTY_RESPONSES = 2;

    private AbstractAiService $aiService;

    private EntryGeneratorService $generator;

    private PromptUrlFetcher $promptUrlFetcher;

    private ?FigmaContentFetcher $figma;

    private ?EntryGenerationBatchService $batch;

    private ?PlanEntryDecorator $decorator;

    public function __construct(
        AbstractAiService $aiService,
        EntryGeneratorService $generator,
        PromptUrlFetcher $promptUrlFetcher,
        ?FigmaContentFetcher $figma = null,
        ?EntryGenerationBatchService $batch = null,
        ?PlanEntryDecorator $decorator = null,
    ) {
        $this->aiService = $aiService;
        $this->generator = $generator;
        $this->promptUrlFetcher = $promptUrlFetcher;
        $this->figma = $figma;
        $this->batch = $batch;
        $this->decorator = $decorator;
    }

    /**
     * Always returns at least one entry. When the LLM cannot produce a usable plan,
     * falls back to single-entry resolution so the existing flow keeps working.
     *
     * @param  callable(): void|null  $streamHeartbeat  Throttled NDJSON keepalive from the CP stream (proxies idle-timeout).
     * @return array{
     *   entries: array<int, array{collection: string, blueprint: string, prompt: string, label: string}>,
     *   warnings: string[],
     *   url_augmentation: array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths, appended_to_prompts: bool},
     * }
     */
    public function plan(string $prompt, ?string $attachmentContent = null, ?string $siteLocale = null, ?callable $streamHeartbeat = null): array
    {
        $catalog = $this->generator->getCollectionsCatalog();

        if ($catalog === []) {
            throw new \RuntimeException(__('No collections with blueprints are available.'));
        }

        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $maxEntries = max(1, min(500, (int) config('statamic-ai-assistant.agent_max_plan_entries', 100)));

        $useUrlTool = (bool) config('statamic-ai-assistant.entry_generator_fetch_url_tool', true)
            && (bool) config('statamic-ai-assistant.prompt_url_fetch.enabled', true)
            && $this->aiService->supportsChatTools();

        $urlToolHint = $useUrlTool
            ? "URL HANDLING RULES — these take priority over any later JSON output rule:\n"
                ."0. Only ever fetch URLs the user explicitly provided (or same-site links found inside those fetched pages). NEVER invent, guess, or try alternative/variant URLs — a guessed URL will be refused. If the user provided NO URL, do not fetch anything: plan from the request and the CMS context alone.\n"
                ."1. When the user DID provide one or more http(s) URLs, you MUST call the **fetch_page_content** tool to retrieve them BEFORE producing JSON. Never guess how many entries to plan from a URL alone.\n"
                ."2. Use the fetched content to decide how many entries to create. If the URL is a homepage, sitemap, listing, or section index on the SAME site, fetch additional sub-pages as needed to enumerate every item the user asked about.\n"
                ."3. For requests like 'all pages of this website', 'every news on this page', or 'one entry per article', produce **one `entries[]` row per distinct article/item** you discovered (capped at {$maxEntries}). Each row must have its own `label` and `prompt` that cites **exactly one** canonical detail-page URL for that item. Never collapse many articles into a single plan row — the downstream generator creates **one CMS entry per plan row**.\n"
                ."4. You may call the tool multiple times. Pass **url** (full link) and **reason** (short: which planning step this supports).\n"
                ."5. ONLY after you have understood the source structure, respond with the JSON object — no markdown fences, no commentary.\n"
                ."---\n\n"
            : '';

        $system = $urlToolHint
            ."You are a Statamic CMS planner. The user describes one or more entries they want to create. "
            ."Split the request into a list of independent entries and pick the best collection + blueprint for each, drawn ONLY from the catalog provided.\n\n"
            ."Return ONLY a JSON object shaped like:\n"
            ."{\"entries\":[{\"collection\":\"<handle>\",\"blueprint\":\"<handle>\",\"label\":\"<2-6 word title>\",\"prompt\":\"<self-contained brief for this single entry>\"}]}\n\n"
            ."Rules:\n"
            ."- If the user asks for one entry, return exactly one item.\n"
            ."- If the user asks for several entries (\"create 2 pages…\", \"a blog post and a page about X\"), return one item per entry, in the order requested.\n"
            ."- If the user wants **one entry per article** (or per listing item), return **one item per article**, not one item that tells the generator to recreate the whole list.\n"
            ."- Cap the list at {$maxEntries} items even if more are requested.\n"
            ."- collection and blueprint MUST match the catalog exactly (case-sensitive). Do not invent handles.\n"
            ."- The blueprint MUST be one listed for the chosen collection.\n"
            ."- If unsure which collection fits, prefer the collection whose handle is \"pages\" if present; otherwise use the first catalog collection.\n"
            ."- The per-entry \"prompt\" must be a complete, self-contained brief in the user's language — include every detail from the user's request that pertains to this entry. Do not reference \"the other entry\" or rely on context outside the prompt.\n"
            ."- The \"label\" is a short human title (2-6 words) for the UI, in the user's language.\n"
            .$this->germanNoEszettPlannerRule($siteLocale)
            ."- Output JSON only. No markdown fences, no commentary.";

        $attachmentPart = $attachmentContent
            ? "\n\nAdditional context from an attached document (excerpt):\n".Str::limit($attachmentContent, 6000)
            : '';

        // When the entry generator will expose fetch_page_content as a tool, skip the
        // server-side URL pre-fetch here too. Otherwise the listing page would be
        // inlined into the planner context and re-passed to the entry generator,
        // leaving the LLM no reason to drill into detail pages.
        $urlAug = $useUrlTool
            ? ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths]
            : $this->promptUrlFetcher->buildAugmentation($prompt);
        $figmaAug = $this->figma ? $this->figma->buildAugmentation($prompt) : ['appendix' => '', 'warnings' => []];

        $user = "Available collections and blueprints (JSON):\n{$catalogJson}\n\nUser request:\n{$prompt}{$urlAug['appendix']}{$figmaAug['appendix']}{$attachmentPart}";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $warnings = [];
        $plannerToolWarnings = [];

        try {
            if ($useUrlTool) {
                $raw = $this->planWithToolLoop($messages, $prompt, $plannerToolWarnings, $streamHeartbeat);
            } else {
                // Same budget as the tool-loop planner: reasoning models think before
                // emitting the JSON plan, and a 1024 cap left them with an empty reply.
                $raw = $this->aiService->generateFromMessages($messages, max(4096, (int) config('statamic-ai-assistant.entry_generator_planner_max_output_tokens', 12000)));
            }
        } catch (\Throwable) {
            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        foreach ($plannerToolWarnings as $w) {
            $warnings[] = $w;
        }

        if ($raw === null || trim($raw) === '') {
            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        try {
            $entries = $this->parseAndNormalize($raw, $catalog, $prompt, $warnings);
        } catch (\RuntimeException $e) {
            Log::warning('[entry-gen-tool] planner JSON parse failed; single-entry fallback', [
                'message' => $e->getMessage(),
                'raw_chars' => strlen($raw),
            ]);

            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        if ($entries === []) {
            return $this->singleEntryFallback($prompt, $attachmentContent, $warnings, $urlAug, $figmaAug);
        }

        if (count($entries) > $maxEntries) {
            $dropped = count($entries) - $maxEntries;
            $entries = array_slice($entries, 0, $maxEntries);
            $warnings[] = __(':n more entries were requested but only the first :max will be created. Ask again to create the rest.', [
                'n' => $dropped,
                'max' => $maxEntries,
            ]);
        }

        foreach ($urlAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        foreach ($figmaAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        $combinedAppendix = $urlAug['appendix'].$figmaAug['appendix'];

        if ($combinedAppendix !== '') {
            foreach ($entries as &$entry) {
                $entry['prompt'] = trim((string) ($entry['prompt'] ?? '')).$combinedAppendix;
            }
            unset($entry);
        }

        return [
            'entries' => $entries,
            'warnings' => $warnings,
            'url_augmentation' => $this->urlAugmentationForResponse($urlAug, $combinedAppendix !== ''),
        ];
    }

    /**
     * Agentic planner: runs inside PlanEntriesJob. Reads prompt/locale/attachment
     * from the batch session, lets the LLM call fetch_page_content + create_entry_job,
     * and dispatches one GeneratePlannedEntryJob per planned article in parallel.
     *
     * Throws so PlanEntriesJob can mark planning_failed; success path calls markPlanningComplete.
     */
    public function planAgentic(string $sessionId): void
    {
        if ($this->batch === null || $this->decorator === null) {
            throw new \RuntimeException('Planner agentic dependencies not bound (EntryGenerationBatchService / PlanEntryDecorator).');
        }

        if (! $this->aiService->supportsChatTools()) {
            throw new \RuntimeException(__('The configured AI provider does not support tool calls; cannot run AI agent planner.'));
        }

        $session = $this->batch->getSession($sessionId);
        if (! is_array($session)) {
            throw new \RuntimeException(__('Planner session not found.'));
        }

        $prompt = (string) ($session['prompt'] ?? '');
        $locale = (string) ($session['locale'] ?? '');
        $attachment = isset($session['attachment_content']) && is_string($session['attachment_content'])
            ? $session['attachment_content']
            : null;

        $shortlistLimit = max(0, (int) config('statamic-ai-assistant.agent_catalog_entries_shortlist', 25));
        $catalog = $this->generator->getCollectionsCatalog($shortlistLimit);
        if ($catalog === []) {
            throw new \RuntimeException(__('No collections with blueprints are available.'));
        }

        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // The session carries the effective cap resolved from the requesting CP
        // user's role (EntryCreationPolicy). Non-super users are limited to a
        // single entry; fall back to the configured cap for legacy sessions.
        $cap = $this->resolvePlanCap($session);

        // Structural tools: registered only when the requesting user held the
        // 'advanced_tools' grant at request time (stored on the session) and the
        // pack isn't disabled site-wide. When off, the model never sees them.
        $advancedTools = AdvancedToolset::enabledForSession($session);

        // Hard single-entry mode for capped (non-super) users: make the one-entry
        // rule impossible to miss, on top of the server-side cap enforcement.
        $singleEntryRule = $cap === 1
            ? "STRICT SINGLE-ENTRY LIMIT: you may create, update, OR localize EXACTLY ONE entry in this request. "
                ."Make a single create_entry_job, update_entry_job, OR localize_entry call, then stop. "
                ."If the user asked for several entries, pick the single best match, do that one, and note in your final summary that only one was created due to the current permission limit.\n\n"
            : '';

        $canLocalize = CpFeatures::translationsEnabled() && Site::all()->count() > 1;
        $siteCatalog = implode('; ', (new SiteLanguageResolver)->catalog());
        $localizeToolLine = $canLocalize
            ? "- `localize_entry`: localize an EXISTING entry onto another site and translate the source content into that localization. Sites: {$siteCatalog}. Use this when the user wants a translation, or \"the EN/FR/IT entry\", of a page that already exists.\n"
            : '';
        $localizeWorkflow = $canLocalize
            ? "2c. LOCALIZE an existing entry instead of duplicating it: when the user wants an existing entry translated into another language, or asks to create the other-language entry of a page (\"translate this page to English\", \"übersetze diese Seite\", \"erstelle den EN-Eintrag\", \"create the EN version\"), call **localize_entry** with that entry's id and the target site. This saves a localization of the source entry on the target site (origin linked) and translates the source content into it. A new entry on the current site is a different page. create_entry_job and update_entry_job do not localize.\n"
            : '2c. Localization is unavailable'
                .(CpFeatures::translationsEnabled() ? ' because only one site is configured' : ' because translations are disabled')
                .". If the user asks to translate an existing entry into another language, say so and stop. create_entry_job would make a different page on the current site, so do not use it as a stand-in translation.\n";

        $system = "You are a Statamic CMS planner running asynchronously. The user described one or more entries they want to create OR update. "
            ."Your job is to figure out which case applies and dispatch tool calls accordingly. **Never produce JSON output yourself** — entries are created or updated exclusively through tool calls.\n\n"
            ."This may be an ongoing conversation: earlier turns appear as prior messages. If the newest user message contains an `@Title` mention, that entry is the one they mean — including \"this page\", \"this entry\", \"diese Seite\" and the entry open in the Control Panel, which is added to the prompt for them. Resolve it with find_entries or read_entry (a title is enough) and do not ask them to name it. Otherwise, when they say \"this entry\", \"it\", \"the same page\", etc., they mean an entry discussed in an earlier turn — the assistant turns note the entries they acted on (with entry_id for updates). Use that id to target it, or call find_entries / search_entry_content to re-resolve it by name. Only act on what the newest user message asks for.\n\n"
            .$singleEntryRule
            ."AVAILABLE TOOLS:\n"
            ."- `fetch_page_content`: read external http(s) URLs — but ONLY URLs the user explicitly provided (or same-site links inside those pages). Never invent, guess, or try alternative/variant URLs; if the user gave no URL, do not fetch anything.\n"
            ."- `create_entry_job`: queue ONE new entry for asynchronous creation. Never use this to translate or localize an existing entry.\n"
            ."- `update_entry_job`: queue an UPDATE to an existing entry (you must know its `entry_id`). This edits that same entry. It does not create a localization.\n"
            .$localizeToolLine
            ."- `find_entries`: search existing entries by title/slug when the catalog shortlist below is not enough.\n"
            ."- `search_entry_content`: find an entry by a phrase/name/value that appears INSIDE its body content (not its title). Use this when the user quotes text from within a page rather than its title.\n"
            ."- `answer_question`: reply to a read-only question without creating or updating anything.\n"
            ."- `propose_plan`: for COMPLEX requests, propose your step-by-step plan (and at most one question) and pause for the user's approval before acting.\n"
            ."- `read_entry_structure`: read an existing entry's layout/components (its sets in order) so a new or updated entry can mirror them. Pass `entry_id` (from the catalog or find_entries) or a title/slug `query`. The reference entry may use a different blueprint — mirror the structure, but each per-entry `prompt` you write must instruct mapping onto the target blueprint's own sets, never copying set handles blindly.\n"
            ."- `read_entry`: read an entry's FULL current field values (raw). Use it to answer questions about an entry's content or to quote exact current values in an update `prompt`. Pass `entry_id` or a title/slug `query`; on large entries re-call it with `fields` to fetch only specific handles.\n"
            ."- `read_globals`: read site-wide global values (general settings, contact details, social links, default CTA links). Call with no args for all sets, or a `handle` for one. Use it to reuse real contact info / CTA links instead of inventing them.\n"
            ."- `read_nav_tree`: read a navigation as a hierarchy of page titles (with URLs + linked entry ids). Call with no args to list navigations, or a `handle` for its tree. Use it to understand site structure or pick internal link targets.\n"
            ."- `list_taxonomies`: list taxonomies, or the terms of one (pass a `taxonomy` handle). Use the returned slugs when a per-entry prompt must set a `terms` field, so you only reference terms that exist.\n"
            ."- `list_assets`: browse asset containers/folders and their files (optional `search` filters by filename). Use it to explore `@folder:container::path` references.\n"
            ."- `read_document`: read the TEXT of a pdf/txt/md/csv document in the asset library (exact \"container::path\" ref). When the user references or asks about a document asset (e.g. `@asset:…report.pdf`), call this — NEVER claim you cannot read PDFs.\n"
            ."- IMPORTANT: `@asset:container::path` and `@folder:container::path` references from the user are EXACT, already-validated references — use everything after `@asset:` / `@folder:` directly as the `ref`/container+folder arguments of the asset tools. NEVER verify their existence with list_assets first, and never claim such a reference does not exist.\n"
            ."- IMPORTANT: `@file:<name>` mentions (e.g. `@file:report.pdf`) are documents the user UPLOADED FROM THEIR BROWSER for THIS message — they are NOT in the asset library. Their extracted text is already provided inline below under \"attached document\" (each labelled `── Attachment: <name> ──`). Read and answer about them, and base entries on them, straight from that inline text. NEVER call read_document, list_assets or fetch_page_content for an `@file:` mention, and NEVER say it cannot be found — it is not a library asset by design.\n"
            ."- IMPORTANT: such references point to FILES in the asset library, never to collections or entries — even when a collection shares the folder's name (folder \"apartments\" ≠ collection \"apartments\"). Questions about them (\"how many\", \"which folder has more\", \"does it have alt text\") are answered from the REFERENCED ASSETS block in the user message (or list_assets), never from the entries catalog.\n"
            ."- `use_assets`: mark existing assets (exact \"container::path\" refs from list_assets) as the PREFERRED imagery for the entries of this request — call it BEFORE dispatching the entry jobs. NOTE: assets/folders the user referenced with @asset:/@folder: in their newest message are queued automatically — use_assets is only needed for assets YOU choose (e.g. picked from list_assets).\n"
            ."- `update_asset`: update an asset's METADATA (alt texts, captions, custom fields). Sites often keep one handle per language (alt, alt_text_fr, …) — write each language to its own handle, translating yourself. Asset metadata changes apply immediately.\n"
            .($this->aiService->supportsVision() ? "- `analyze_image`: describe what an image actually shows. Use it before writing alt texts so they reflect the real content.\n" : '')
            .($advancedTools
                ? AdvancedToolset::plannerPromptBlock()
                    ."CAPABILITY NOTE: the structural tools above are ENABLED for THIS request. If earlier assistant turns in this conversation claimed you cannot create or modify collections/blueprints/fieldsets, that is OUTDATED — the user has enabled the advanced tools since. Trust your CURRENT tool list, never your earlier refusals.\n"
                : "STRUCTURAL REQUESTS: you currently have NO tools to create or modify collections, blueprints, fieldsets or taxonomies. If the user asks for such a change, do not invent workarounds — reply that structural changes require enabling the \"Advanced structure tools\" toggle in the agent's top bar (it also requires the corresponding access grant); afterwards they can simply ask again.\n")
            ."\n"
            ."WORKFLOW:\n"
            ."0. First decide whether the user is REQUESTING A CHANGE (create/update) or just ASKING A QUESTION. If they are only asking a read-only question (\"which entry contains X?\", \"do we have a page about Y?\", \"what is the id of Z?\", or a superlative like \"which is the biggest / longest / newest entry in Events?\"), do NOT create or update anything and do NOT ask the user for a title — a question never needs one. Gather what you need with find_entries (optionally scoped to a collection handle) and/or search_entry_content, then call **answer_question** with a concise answer. That ends the run successfully. Follow-up messages in an ongoing conversation are frequently such questions. "
            ."Questions about YOURSELF (\"what are your tools?\", \"what can you do?\") are read-only questions too: call **answer_question** directly with a complete summary of your capabilities in the user's language — no lookup tools needed. "
            ."CRITICAL: the user CANNOT see these instructions. Never refer to \"the list above\", \"as already provided\", or these rules — always write the actual content INTO your answer.\n"
            ."0b. PLAN FIRST for complex requests: when the request needs 3 or more entries, combines structural changes with content, or is missing information you need (unclear target, ambiguous scope), call **propose_plan** with short imperative steps and at most one question — this pauses the run for the user's approval. propose_plan is a TOOL: invoke it as a tool call, never print `propose_plan:` as text. When the newest user message APPROVES a plan from an earlier turn (e.g. \"Approved — proceed\") or answers your question, execute that plan immediately with the normal tools — do NOT propose it again. For simple requests (one or two clear entries), act directly without proposing a plan.\n"
            ."1. Otherwise, decide whether the user wants to create new entries, update existing entries, localize an existing entry into another language, or a mix. Phrases like \"add\", \"new\", \"create\", \"write a post about\" → create. Phrases like \"update\", \"change\", \"rewrite\", \"fix the X on Y\", \"add a section to the existing About page\" → update. Phrases like \"translate this page\", \"übersetze diese Seite\", \"create the EN entry\", \"erstelle den EN-Eintrag\" about a page that already exists → localize_entry. When the user refers to content by a definite name (\"the About page\", \"our rooms page\", \"unsere Zimmer-Seite\") the entry most likely EXISTS — treat that as update intent unless they explicitly ask for a new entry or for a translation.\n"
            ."2. For UPDATES: find the target entry's `entry_id`. A title alone is ENOUGH to identify an entry — never claim the target is ambiguous or unknown just because the user only gave a title. The catalog below carries an `entries` shortlist (recently updated) and a `count` per collection. First scan the shortlist and match the named entry by title, ignoring case, punctuation and connective words (so \"Salt Stone\", \"Salt and Stone\" and \"Salt & Stone\" all refer to the same entry); if you find it, use its id directly. If it is not in the shortlist, you MUST call **find_entries** with the title (and optionally a collection handle) before concluding anything. If the user identifies the entry by a phrase or value from its body/content rather than a title, call **search_entry_content** instead. Only after searching returns no match may you give up. If the intent is clearly UPDATE but neither the shortlist nor find_entries locates the entry, do NOT fall back to creating a new entry — end with `Cannot proceed:` naming the entry you could not find.\n"
            ."2b. AVOID DUPLICATES on creates: before dispatching create_entry_job for content that may already exist (a topic matching an entry title in the shortlist, or a fetched URL whose title matches an existing entry), check the shortlist or call find_entries. If a matching entry exists and the user's wording is compatible with updating it, prefer update_entry_job over creating a near-duplicate.\n"
            .$localizeWorkflow
            ."3. For CREATES with URLs in the user message, call **fetch_page_content** first to inspect them. For listing/index URLs on the same site, enumerate every relevant detail page. Do NOT fetch URLs the user did not provide — never guess or try URLs that might exist.\n"
            ."4. Dispatch each entry as soon as you have enough info — do not wait to plan all entries before dispatching. Each tool call enqueues a worker that starts immediately, in parallel.\n"
            ."5. Use one tool call per distinct entry. Never collapse many entries into a single call. The combined cap is {$cap} create_entry_job + update_entry_job + localize_entry calls per request.\n"
            ."6. For CREATES: pick collection + blueprint **only** from the catalog below (handles must match exactly, case-sensitive). The chosen blueprint must be one listed for that collection. If unsure, prefer the collection whose handle is `pages` if present; otherwise the first catalog collection.\n"
            ."7. Each `prompt` MUST be a complete, self-contained brief in the user's language: include the URL (when applicable), the topic, and any constraints. For updates, describe ONLY what should change — do not restate the rest of the entry. Do not reference other entries.\n"
            ."8. The `label` is a short human title (2-6 words) for the UI in the user's language.\n"
            .$this->germanNoEszettPlannerRule($locale)
            ."9. When you have done everything the newest user message asked for (or hit the cap), end your turn with a short plain-text summary (1-3 sentences, no JSON, no tool calls) naming CONCRETELY what you did: which entries, which fields/languages, which assets or structures. Example: `Updated the alt text of martina-seiler.jpg in DE, FR, EN and IT based on the image content.` Never reply with just `Done` or a bare action count.\n"
            ."10. If the request is impossible, end your turn with a short plain-text explanation starting with `Cannot proceed:`. But NEVER give up on an update target without first checking the shortlist AND calling find_entries — a bare title is not a reason to stop.";

        // Editor-maintained site-wide instructions (AI instructions page) —
        // the site-level analog of a coding agent's project memory file.
        $siteInstructions = (new SetHintsService)->siteInstructions();
        if ($siteInstructions !== '') {
            $system .= "\n\nSITE INSTRUCTIONS (set by this site's editors — always follow them):\n"
                .Str::limit($siteInstructions, 4000);
        }

        $attachmentPart = $attachment
            ? "\n\nAdditional context from an attached document (excerpt):\n".Str::limit($attachment, 6000)
            : '';

        if ($attachment) {
            // The user attached a document: it IS the source material. Make the
            // planner base every planned entry on the attachment's content, keep
            // the relevant facts inside each per-entry prompt, and — critically —
            // NOT call fetch_page_content for it (the worker re-appends the full
            // attachment and is told to use it, not go online).
            $system .= "\n\nATTACHED DOCUMENT RULES:\n"
                ."- The user uploaded one or more documents from their browser for this message (referenced in the prompt as `@file:<name>`); their extracted text is included below, each labelled `── Attachment: <name> ──`. This inline text IS the document content — it is NOT in the asset library.\n"
                ."- To match an `@file:report.pdf` mention to its content, find the `── Attachment: report.pdf ──` block below; do not call read_document / list_assets / fetch_page_content for it.\n"
                ."- NEVER fabricate a document's contents. Use ONLY what is in its `── Attachment ──` block. If a block is marked `(UNREADABLE — …)`, its text could not be extracted (e.g. a scanned/image-only PDF): tell the user plainly that you could not read that file and why, and do NOT guess what it contains from its name. Inventing a plausible document is a serious error.\n"
                ."- If the user is only ASKING about a readable attachment (\"what is in @file:x.pdf\", \"summarise it\"), call **answer_question** with the CONTENT they asked for — quote or summarise the document's actual text into the `answer`. The answer must BE that content; never reply that you have answered, that the text was provided, or that no lookup was needed.\n"
                ."- When creating/updating: treat the attachment as the PRIMARY source. In every create_entry_job / update_entry_job `prompt`, include the concrete facts, names, numbers, structure and wording from the attachment that the worker needs to write THAT entry. A generic brief like \"create a page about garages\" is not enough — transcribe the relevant portion into the prompt.\n"
                ."- If an attachment is too long for one entry, split its content across multiple create_entry_job calls (one per section/item), each prompt carrying that section's content.\n";
        }

        // Build the message array from the conversation transcript so follow-up
        // turns carry context. Only the newest user turn carries the catalog +
        // attachment. On turn 1 the transcript is exactly [user], so this yields
        // the same [system, user] shape as a single-shot request (regression-tested).
        $transcript = is_array($session['transcript'] ?? null) && $session['transcript'] !== []
            ? $session['transcript']
            : [['role' => 'user', 'text' => $prompt, 'entry_ids' => [], 'kind' => null]];

        $lastUserIdx = null;
        $userTexts = [];
        foreach ($transcript as $i => $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $lastUserIdx = $i;
                $userTexts[] = (string) ($turn['text'] ?? '');
            }
        }

        // Pre-resolve "@asset:"/"@folder:" mentions from EVERY user turn (refs are
        // often dropped in one message and asked about later) into an authoritative
        // block on the newest user message — models otherwise answer folder
        // questions from the entries catalog when a collection shares the name.
        $assetMentionPart = (new PromptAssetMentionResolver)->resolve($userTexts)['appendix'];

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($transcript as $i => $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = (string) ($turn['text'] ?? '');
            if ($role === 'assistant') {
                $text .= $this->renderEntryRefs(
                    is_array($turn['entry_ids'] ?? null) ? $turn['entry_ids'] : [],
                    $session,
                );
            }
            if ($i === $lastUserIdx) {
                $text = "Available collections and blueprints (JSON):\n{$catalogJson}\n\nUser request:\n{$text}{$attachmentPart}{$assetMentionPart}";
            }
            if (trim($text) === '') {
                continue; // never send an empty message (some providers reject them)
            }
            $messages[] = ['role' => $role, 'content' => $text];
        }

        // Absolute cap for addPlannedEntry: prior turns' entries accumulate in the
        // session, so a follow-up gets its own per-turn allowance on top of them.
        $priorEntryCount = is_array($session['entry_order'] ?? null) ? count($session['entry_order']) : 0;

        $heartbeat = function () use ($sessionId): void {
            // Cancellation poll between LLM rounds + tool calls; throwing exits the loop.
            if ($this->batch !== null && $this->batch->isCancelled($sessionId)) {
                throw new \RuntimeException((string) __('Cancelled.'));
            }
        };

        $toolWarnings = [];
        $createdRowIds = [];
        $structuralChanges = [];
        $this->planAgenticToolLoop(
            $messages,
            $prompt,
            $sessionId,
            $catalog,
            $cap,
            $toolWarnings,
            $heartbeat,
            $priorEntryCount,
            $createdRowIds,
            $advancedTools,
            $structuralChanges,
        );

        foreach ($toolWarnings as $w) {
            if (is_string($w) && $w !== '') {
                $this->batch->appendPlannerWarning($sessionId, $w);
            }
        }

        // Record this turn's outcome in the transcript so the next turn has context.
        // The answer_question / propose_plan / structural-only paths already
        // recorded their own assistant turn in the loop. A MIXED run (entries +
        // structural changes) lands here — the summary must name both, or the
        // collection/blueprint work silently disappears from the visible record.
        if ($createdRowIds !== []) {
            $summary = $this->summarizeCreatedEntries($createdRowIds, $sessionId);

            if ($structuralChanges !== []) {
                $summary .= "\n".__('Also applied: :list.', ['list' => implode(', ', $structuralChanges)]);
            }

            $this->batch->appendAssistantTurn($sessionId, $summary, $createdRowIds, 'summary');
        }

        $this->batch->markPlanningComplete($sessionId);
    }

    /**
     * A short human-readable suffix naming the entries an assistant turn acted on,
     * so a later "add a section to this entry" can resolve the target.
     *
     * @param  array<int, string>  $entryRowIds  Session plan-row ids from a prior turn.
     * @param  array<string, mixed>  $session
     */
    private function renderEntryRefs(array $entryRowIds, array $session): string
    {
        $entries = is_array($session['entries'] ?? null) ? $session['entries'] : [];
        $parts = [];
        foreach ($entryRowIds as $rid) {
            $row = $entries[(string) $rid] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $label = (string) ($row['label'] ?? '');
            $op = (string) ($row['operation'] ?? 'create');
            $statamicId = isset($row['entry_id']) && is_string($row['entry_id']) ? $row['entry_id'] : '';
            $desc = $label !== '' ? "“{$label}”" : (string) ($row['collection_title'] ?? ($row['collection'] ?? 'entry'));
            if ($op === 'update' && $statamicId !== '') {
                $desc .= " (entry_id {$statamicId})";
            }
            if ($op === 'localize' && $statamicId !== '') {
                $targetSite = isset($row['target_site']) && is_string($row['target_site']) ? $row['target_site'] : '';
                $desc .= " (source entry_id {$statamicId}".($targetSite !== '' ? ", site {$targetSite}" : '').')';
            }
            $desc .= " [{$op}]";
            $parts[] = $desc;
        }

        return $parts === [] ? '' : "\n(Entries this turn: ".implode('; ', $parts).')';
    }

    /**
     * Deterministic summary of the entries dispatched this turn (ids/labels are
     * guaranteed correct, unlike the model's free-text tail).
     *
     * @param  array<int, string>  $createdRowIds
     */
    private function summarizeCreatedEntries(array $createdRowIds, string $sessionId): string
    {
        $session = $this->batch?->getSession($sessionId);
        $entries = is_array($session['entries'] ?? null) ? $session['entries'] : [];

        $created = [];
        $updated = [];
        $localized = [];
        foreach ($createdRowIds as $rid) {
            $row = $entries[(string) $rid] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $label = (string) ($row['label'] ?? '');
            $name = $label !== '' ? "“{$label}”" : (string) ($row['collection_title'] ?? ($row['collection'] ?? 'entry'));
            $operation = (string) ($row['operation'] ?? 'create');
            if ($operation === 'update') {
                $updated[] = $name;
            } elseif ($operation === 'localize') {
                $site = (string) ($row['target_site_label'] ?? $row['target_site'] ?? '');
                $localized[$site][] = $name;
            } else {
                $created[] = $name;
            }
        }

        $clauses = [];
        if ($created !== []) {
            $clauses[] = (string) __('created :names', ['names' => implode(', ', $created)]);
        }
        if ($updated !== []) {
            $clauses[] = (string) __('updated :names', ['names' => implode(', ', $updated)]);
        }
        foreach ($localized as $site => $names) {
            $clauses[] = (string) __('localized :names into :site', [
                'names' => implode(', ', $names),
                'site' => $site !== '' ? $site : (string) __('another site'),
            ]);
        }

        if ($clauses === []) {
            $n = count($createdRowIds);

            return (string) __('Done — :n actions dispatched.', ['n' => $n]);
        }

        // "Done — created “A”, “B” and updated “C”."
        return (string) __('Done — :summary.', ['summary' => implode(' '.__('and').' ', $clauses)]);
    }

    /**
     * Multi-turn planner completion: lets the model call fetch_page_content
     * to inspect the source site before deciding how many entries to plan.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, string>  $toolWarningsOut
     * @param  callable(): void|null  $streamHeartbeat
     */
    private function planWithToolLoop(array $messages, string $userPrompt, array &$toolWarningsOut, ?callable $streamHeartbeat = null): string
    {
        $restrictFetch = (bool) config('statamic-ai-assistant.entry_generator_restrict_fetch_to_prompt_urls', true);
        $allowedFetchHosts = UrlFetchTool::hostsFromMessages($this->promptUrlFetcher, $messages);

        $runner = new ChatToolRunner([
            new UrlFetchTool($this->promptUrlFetcher, $allowedFetchHosts, $restrictFetch),
            new ReadEntryStructureTool(
                new EntryStructureSerializer,
                fn (?string $collection, string $query, int $limit) => $this->generator->findEntriesShortlist($collection, $query, $limit),
            ),
            new ReadEntryTool(
                fn (?string $collection, string $query, int $limit) => $this->generator->findEntriesShortlist($collection, $query, $limit),
            ),
            new ReadGlobalsTool,
            new ReadNavTreeTool,
            new ListTaxonomiesTool,
        ], new ToolContext(
            warningSink: function (string $w) use (&$toolWarningsOut) {
                $toolWarningsOut[] = $w;
            },
            heartbeat: $streamHeartbeat,
        ));

        $maxRounds = (int) config('statamic-ai-assistant.entry_generator_tool_max_rounds', 120);
        // Planner JSON can be huge (many URLs + briefs); keep separate from per-entry generator_max_tokens.
        $maxTokens = max(4096, (int) config('statamic-ai-assistant.entry_generator_planner_max_output_tokens', 12000));

        $promptHasUrl = (bool) preg_match('~\bhttps?://[^\s<>\]\}\)\"\'`]+~iu', $userPrompt);
        $working = $messages;

        for ($round = 0; $round < $maxRounds; $round++) {
            $streamHeartbeat?->__invoke();

            $forceTool = $promptHasUrl && $runner->callCount('fetch_page_content') === 0;
            $toolChoice = $forceTool
                ? ['type' => 'function', 'function' => ['name' => 'fetch_page_content']]
                : 'auto';

            ChatToolRunner::compactToolMessages($working);

            $data = $this->aiService->createChatCompletion($working, $maxTokens, $runner->definitions(), $toolChoice, $streamHeartbeat);
            $choice = $data['choices'][0] ?? null;
            $msg = is_array($choice) ? ($choice['message'] ?? null) : null;

            if (! is_array($msg)) {
                throw new \RuntimeException(__('Unexpected planner response shape.'));
            }

            $toolCalls = $msg['tool_calls'] ?? null;
            $hasToolCalls = is_array($toolCalls) && $toolCalls !== [];

            if ($forceTool && ! $hasToolCalls) {
                Log::warning('[entry-gen-tool] planner forced tool call was IGNORED', [
                    'round' => $round,
                    'tool_choice_sent' => $toolChoice,
                ]);
            }

            if ($hasToolCalls) {
                $working[] = [
                    'role' => 'assistant',
                    'content' => array_key_exists('content', $msg) ? $msg['content'] : null,
                    'tool_calls' => $toolCalls,
                ];

                $runner->consume($toolCalls, $working);

                continue;
            }

            $content = $msg['content'] ?? '';
            if (is_string($content) && trim($content) !== '') {
                Log::info('[entry-gen-tool] planner done', [
                    'rounds' => $round + 1,
                    'fetches' => $runner->callCount('fetch_page_content'),
                ]);

                return $content;
            }

            throw new \RuntimeException(__('Planner returned no usable text after tool use.'));
        }

        Log::error('[entry-gen-tool] planner tool loop exceeded max rounds', [
            'max_rounds' => $maxRounds,
            'fetches' => $runner->callCount('fetch_page_content'),
        ]);
        throw new \RuntimeException(__('Planner stopped: too many tool rounds.'));
    }

    /**
     * Agentic tool loop: same shape as planWithToolLoop but exposes both
     * fetch_page_content and create_entry_job. Each create_entry_job call
     * appends a row to the batch session and dispatches GeneratePlannedEntryJob.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @param  array<int, string>  $toolWarningsOut
     * @param  callable(): void|null  $streamHeartbeat
     */
    private function planAgenticToolLoop(
        array $messages,
        string $userPrompt,
        string $sessionId,
        array $catalog,
        int $cap,
        array &$toolWarningsOut,
        ?callable $streamHeartbeat = null,
        int $priorEntryCount = 0,
        array &$createdRowIdsOut = [],
        bool $advancedTools = false,
        array &$structuralChangesOut = [],
    ): void {
        // Shared runner for the stateless read tools (URL fetch + entry-structure
        // read). The stateful job tools (create/update/find) stay outside the
        // runner and are handled via the $fallback in consume() below, since they
        // mutate session/cap state that a generic runner shouldn't own.
        // Existing assets the model selects via use_assets land here and are
        // drained into the session after every tool round, so already-dispatched
        // and future entry jobs alike pick them up as preferred imagery.
        $selectedAssets = new PreferredAssetPaths;

        $toolContext = new ToolContext(
            warningSink: function (string $w) use (&$toolWarningsOut) {
                $toolWarningsOut[] = $w;
            },
            heartbeat: $streamHeartbeat,
            imageSink: $selectedAssets,
            activitySink: fn (string $line) => $this->batch?->appendPlannerActivity($sessionId, $line),
        );
        $restrictFetch = (bool) config('statamic-ai-assistant.entry_generator_restrict_fetch_to_prompt_urls', true);
        $allowedFetchHosts = UrlFetchTool::hostsFromMessages($this->promptUrlFetcher, $messages);

        $toolset = [
            new UrlFetchTool($this->promptUrlFetcher, $allowedFetchHosts, $restrictFetch),
            new ReadEntryStructureTool(
                new EntryStructureSerializer,
                fn (?string $collection, string $query, int $limit) => $this->generator->findEntriesShortlist($collection, $query, $limit),
            ),
            new ReadEntryTool(
                fn (?string $collection, string $query, int $limit) => $this->generator->findEntriesShortlist($collection, $query, $limit),
            ),
            new ReadGlobalsTool,
            new ReadNavTreeTool,
            new ListTaxonomiesTool,
            new ListAssetsTool,
            new UseAssetsTool,
            new UpdateAssetTool,
            new \MotionWork\StatamicAiAssistant\Tools\ReadDocumentTool,
        ];

        if ($this->aiService->supportsVision()) {
            $toolset[] = new AnalyzeImageTool($this->aiService);
        }

        // Structural tools are appended only for sessions whose requesting user
        // holds the 'advanced_tools' grant — otherwise they don't exist as far
        // as the model is concerned.
        if ($advancedTools) {
            $toolset = array_merge($toolset, AdvancedToolset::tools());
        }

        // Successful structural writes (create_collection, create_blueprint, …)
        // count as real work: a run that only changed structure must complete
        // successfully even though no entry jobs were dispatched. Each one is
        // also recorded as a human-readable line for the final summary turn.
        $structuralWrites = 0;
        $structuralNames = AdvancedToolset::writeToolNames();

        $runner = new ChatToolRunner($toolset, $toolContext, function (string $name, array $result) use (&$structuralWrites, &$structuralChangesOut, $structuralNames, $sessionId, $selectedAssets): void {
            // Drain use_assets picks into the session IMMEDIATELY — a
            // create_entry_job later in the same tool round must already see
            // them as preferred imagery when its queue job starts.
            if ($name === 'use_assets' && ! $selectedAssets->isEmpty()) {
                $this->batch?->appendPreferredAssetPaths($sessionId, $selectedAssets->remainingEntries());
            }

            // Asset metadata writes are real work too: a run that only filled
            // alt texts must complete successfully (no entry jobs dispatched),
            // appear in the live operations feed, and be named in the summary.
            // No-op calls (updated=false: every value already matched) are NOT
            // work and must never surface in the applied-changes list.
            if ($name === 'update_asset' && ($result['ok'] ?? false) === true && ($result['updated'] ?? false) === true) {
                $structuralWrites++;
                $description = (string) __('asset ":file" updated (:fields)', [
                    'file' => basename((string) ($result['ref'] ?? '')),
                    'fields' => implode(', ', array_filter((array) ($result['applied'] ?? []), 'is_string')),
                ]);
                $structuralChangesOut[] = $description;
                $this->batch?->appendOperation($sessionId, 'asset', $description);

                return;
            }

            // create_terms adds N terms in one call, so it carries a list rather
            // than a single `handle`. Report the concrete new terms. A call where
            // every term already existed (created == []) is a no-op and must not
            // surface as work — otherwise a "nothing new" run looks like a change.
            if ($name === 'create_terms' && ($result['ok'] ?? false) === true) {
                $newTerms = array_values(array_filter((array) ($result['created'] ?? []), 'is_string'));
                if ($newTerms === []) {
                    return;
                }
                $structuralWrites++;
                $description = (string) __('terms :list added to taxonomy ":taxonomy"', [
                    'list' => implode(', ', $newTerms),
                    'taxonomy' => (string) ($result['taxonomy'] ?? ''),
                ]);
                $structuralChangesOut[] = $description;
                $this->batch?->appendOperation($sessionId, 'taxonomy', $description);

                return;
            }

            if (($result['ok'] ?? false) !== true || ! in_array($name, $structuralNames, true)) {
                return;
            }

            $structuralWrites++;

            $handle = isset($result['handle']) && is_string($result['handle']) ? $result['handle'] : '';
            if ($handle === '') {
                return;
            }

            $description = (string) match ($name) {
                'create_collection' => __('new collection ":handle"', ['handle' => $handle]),
                'configure_collection' => __('collection ":handle" configured', ['handle' => $handle]),
                'create_taxonomy' => __('new taxonomy ":handle"', ['handle' => $handle]),
                'create_blueprint' => __('new blueprint ":handle"', ['handle' => $handle]),
                'update_blueprint' => __('blueprint ":handle" updated', ['handle' => $handle]),
                'create_fieldset' => __('new fieldset ":handle"', ['handle' => $handle]),
                'add_component_set' => __('component ":handle" registered in ":container"', ['handle' => $handle, 'container' => (string) ($result['container'] ?? '')]),
                default => __('structural change (:name)', ['name' => $name]),
            };

            $structuralChangesOut[] = $description;

            // Persistent per-turn operations checklist for the CP progress UI —
            // entries get cards, structural work gets these rows.
            $this->batch?->appendOperation($sessionId, explode('_', $name)[1] ?? 'structure', $description);
        });

        // Seed the activity feed so the CP never shows an empty "0 entries" panel
        // while the planner is thinking before its first tool call.
        $this->batch?->appendPlannerActivity($sessionId, (string) __('Analyzing your request…'));

        $tools = array_merge($runner->definitions(), [
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
        ]);
        $maxRounds = (int) config('statamic-ai-assistant.entry_generator_tool_max_rounds', 120);
        $maxTokens = max(4096, (int) config('statamic-ai-assistant.entry_generator_planner_max_output_tokens', 12000));
        $created = 0;
        // Counts every entry-locating search (find_entries + search_entry_content);
        // used to decide whether a give-up happened without any search attempt.
        $searchCalls = 0;
        // One-shot guard for the "gave up without searching" self-correction below.
        $nudgedEmptyResult = false;
        // Bounds recovery from empty model responses (no content + no tool calls).
        $emptyResponses = 0;
        // Bounds recovery from protocol drift (tool calls PRINTED as text).
        $protocolCorrections = 0;
        $toolNames = array_map(fn ($t) => strtolower((string) ($t['function']['name'] ?? '')), $tools);
        // Set when the model calls answer_question — a read-only answer, not a failure.
        $plannerAnswer = null;
        // Set when the model calls propose_plan — pauses the run for user approval.
        $plannerPlan = null;

        $promptHasUrl = (bool) preg_match('~\bhttps?://[^\s<>\]\}\)\"\'`]+~iu', $userPrompt);
        // Some providers/models don't honour a forced specific-tool choice and
        // return an empty turn. Once we see the forced fetch ignored, stop forcing
        // and let the model proceed with tool_choice=auto (it can still fetch on
        // its own) — otherwise it can get stuck emitting empty responses.
        $forceToolDisabled = false;
        $working = $messages;

        for ($round = 0; $round < $maxRounds; $round++) {
            $streamHeartbeat?->__invoke();

            $forceTool = ! $forceToolDisabled && $promptHasUrl && $runner->callCount('fetch_page_content') === 0 && $created === 0;
            $toolChoice = $forceTool
                ? ['type' => 'function', 'function' => ['name' => 'fetch_page_content']]
                : 'auto';

            ChatToolRunner::compactToolMessages($working);

            // Transient status reminder (Claude Code's system-reminder pattern):
            // appended to THIS call only, never stored in $working — so there is
            // always exactly one and it is always current. Small models drift
            // over long loops; a short recency-anchored status beats hoping they
            // remember the system prompt from round 1.
            $callMessages = $working;
            if ($round > 0) {
                $callMessages[] = [
                    'role' => 'user',
                    'content' => $this->buildLoopStatusReminder($created, $cap, $structuralWrites, $round, $maxRounds, $advancedTools),
                ];
            }

            $data = $this->aiService->createChatCompletion($callMessages, $maxTokens, $tools, $toolChoice, $streamHeartbeat);
            $choice = $data['choices'][0] ?? null;
            $msg = is_array($choice) ? ($choice['message'] ?? null) : null;

            if (! is_array($msg)) {
                throw new \RuntimeException(__('Unexpected planner response shape.'));
            }

            $msg = $this->normalizeAssistantMessage($msg);

            $toolCalls = $msg['tool_calls'] ?? null;
            $hasToolCalls = is_array($toolCalls) && $toolCalls !== [];

            if ($forceTool && ! $hasToolCalls) {
                // The model ignored the forced tool — stop forcing so subsequent
                // rounds use tool_choice=auto and it can move forward.
                $forceToolDisabled = true;

                Log::warning('[entry-gen-tool] agentic planner forced tool call IGNORED; relaxing to auto', [
                    'round' => $round,
                    'tool_choice_sent' => $toolChoice,
                ]);
            }

            if ($hasToolCalls) {
                $working[] = [
                    'role' => 'assistant',
                    'content' => array_key_exists('content', $msg) ? $msg['content'] : null,
                    'tool_calls' => $toolCalls,
                ];

                // Read tools (fetch_page_content, read_entry_structure) go through
                // the shared runner. The stateful job tools are handled in the
                // fallback so their session/cap mutations stay in the planner.
                // Absolute cap for addPlannedEntry (prior turns' entries + this
                // turn's allowance); $cap stays the per-turn model limit.
                $addCap = $priorEntryCount + $cap;

                $runner->consume($toolCalls, $working, function (string $name, string $args) use ($sessionId, $catalog, $cap, $addCap, &$created, &$searchCalls, &$plannerAnswer, &$plannerPlan, &$createdRowIdsOut) {
                    if ($name === 'propose_plan') {
                        try {
                            $decoded = json_decode($args, true, 512, JSON_THROW_ON_ERROR);
                        } catch (\JsonException) {
                            return ['ok' => false, 'error' => 'invalid_arguments_json'];
                        }

                        $steps = [];
                        foreach ((is_array($decoded['steps'] ?? null) ? $decoded['steps'] : []) as $step) {
                            if (is_string($step) && trim($step) !== '') {
                                $steps[] = trim($step);
                            }
                        }

                        if ($steps === []) {
                            return ['ok' => false, 'error' => 'missing_steps'];
                        }

                        $question = isset($decoded['question']) && is_string($decoded['question']) ? trim($decoded['question']) : '';
                        $plannerPlan = ['steps' => array_slice($steps, 0, 12), 'question' => $question];

                        return ['ok' => true];
                    }

                    if ($name === 'answer_question') {
                        try {
                            $decoded = json_decode($args, true, 512, JSON_THROW_ON_ERROR);
                        } catch (\JsonException) {
                            return ['ok' => false, 'error' => 'invalid_arguments_json'];
                        }
                        $answer = is_array($decoded) && isset($decoded['answer']) && is_string($decoded['answer'])
                            ? trim($decoded['answer'])
                            : '';
                        if ($answer === '') {
                            return ['ok' => false, 'error' => 'missing_answer'];
                        }
                        $plannerAnswer = $answer;

                        return ['ok' => true];
                    }

                    if ($name === 'create_entry_job') {
                        $result = $this->handleCreateEntryJobToolCall($args, $sessionId, $catalog, $cap, $created, $addCap);
                        if (($result['ok'] ?? false) === true) {
                            $created++;
                            if (isset($result['id']) && is_string($result['id'])) {
                                $createdRowIdsOut[] = $result['id'];
                            }
                        }

                        return $result;
                    }

                    // AI agent UPDATE tool — independent of create_entry_job.
                    if ($name === 'update_entry_job') {
                        $result = $this->handleUpdateEntryJobToolCall($args, $sessionId, $catalog, $cap, $created, $addCap);
                        if (($result['ok'] ?? false) === true) {
                            $created++;
                            if (isset($result['id']) && is_string($result['id'])) {
                                $createdRowIdsOut[] = $result['id'];
                            }
                        }

                        return $result;
                    }

                    if ($name === 'localize_entry') {
                        $result = $this->handleLocalizeEntryToolCall($args, $sessionId, $catalog, $cap, $created, $addCap);
                        if (($result['ok'] ?? false) === true) {
                            $created++;
                            if (isset($result['id']) && is_string($result['id'])) {
                                $createdRowIdsOut[] = $result['id'];
                            }
                        }

                        return $result;
                    }

                    if ($name === 'find_entries') {
                        $searchCalls++;

                        return $this->handleFindEntriesToolCall($args);
                    }

                    if ($name === 'search_entry_content') {
                        $searchCalls++;

                        return $this->handleSearchEntryContentToolCall($args);
                    }

                    return null;
                });

                // propose_plan is terminal: the plan is shown to the user, who
                // approves or answers in their next message (a follow-up turn).
                if ($plannerPlan !== null) {
                    $this->completePlannerPlan($sessionId, $plannerPlan['steps'], $plannerPlan['question'], $round);

                    return;
                }

                // answer_question is terminal: the model answered a read-only
                // question. Record it as a successful result (not a failure) and stop.
                if ($plannerAnswer !== null) {
                    $this->completePlannerAnswer($sessionId, $plannerAnswer, $round, $created);

                    return;
                }

                continue;
            }

            $content = $msg['content'] ?? '';
            if (is_string($content) && trim($content) !== '') {
                // Some models emit answer_question as plain assistant text instead of
                // a structured tool_call (e.g. answer_question:{"answer":"…"}). Treat
                // that as a successful read-only answer, not a planning failure.
                $plainAnswer = $this->extractAnswerQuestion($content);
                if ($plainAnswer !== null) {
                    $this->completePlannerAnswer($sessionId, $plainAnswer, $round, $created);

                    return;
                }

                // Same recovery for propose_plan printed as text ("propose_plan:
                // …"): record it as a real plan turn so the CP shows the plan
                // panel with the approve action instead of a raw text blob.
                $plainPlan = $this->extractProposedPlan($content);
                if ($plainPlan !== null) {
                    $this->recordPlanTurn($sessionId, $plainPlan, $round);

                    return;
                }

                // GENERIC protocol-drift recovery: the model wrote a TOOL CALL
                // as reply text ("find_entries: …"). Works for every registered
                // tool — bounce it back with a format correction (bounded), and
                // never let such text through as an answer or an error message.
                $printedTool = $this->printedToolCallName($content, $toolNames);
                if ($printedTool !== null) {
                    if ($protocolCorrections < 2) {
                        $protocolCorrections++;

                        Log::warning('[entry-gen-tool] tool call printed as text; correcting', [
                            'round' => $round + 1,
                            'tool' => $printedTool,
                            'attempt' => $protocolCorrections,
                        ]);

                        $working[] = ['role' => 'assistant', 'content' => $content];
                        $working[] = [
                            'role' => 'user',
                            'content' => "FORMAT ERROR: you wrote `{$printedTool}` as plain text in your reply. Tools are invoked ONLY as native tool calls — never written into the reply text. Repeat your intended action NOW as a proper tool call to `{$printedTool}`. Reply text must never contain tool names.",
                        ];

                        continue;
                    }

                    throw new \RuntimeException((string) __('The AI model kept responding in an invalid format. Please try again.'));
                }

                // Self-correction: mid-tier models sometimes bail with "Cannot
                // proceed / I can't determine which entry" WITHOUT ever calling a
                // search tool — even for a read-only question they could just answer,
                // or when the entry sits in the catalog shortlist. If nothing was
                // dispatched and NO tool at all was used (any read tool counts —
                // a model that just listed assets to answer a question must not be
                // told "you have not used any tools", or it discards its own good
                // answer and replies with meta like "I have already answered"),
                // nudge the model to either answer the question or resolve the
                // target before accepting the give-up. Fires at most once, so a
                // truly-impossible request still ends.
                if ($created === 0 && $structuralWrites === 0 && $searchCalls === 0
                    && $runner->totalCalls() === 0 && ! $nudgedEmptyResult) {
                    $nudgedEmptyResult = true;

                    Log::info('[entry-gen-tool] agentic planner bailed without searching; nudging retry', [
                        'round' => $round + 1,
                    ]);

                    $working[] = [
                        'role' => 'assistant',
                        'content' => $content,
                    ];
                    $working[] = [
                        'role' => 'user',
                        'content' => 'Do not give up yet — you have not used any tools. First re-read the newest user message and decide the intent:'."\n"
                            .'• IF IT IS A READ-ONLY QUESTION (e.g. "which is the biggest/longest entry?", "which entry contains X?", "do we have a page about Y?", "what is the id of Z?"): this is NOT a create/update request and needs no title from the user. Use find_entries (optionally scoped to a collection handle) and/or search_entry_content to gather what you need, then call answer_question with a concise answer. Never reply "Cannot proceed:" for a question you can look up.'."\n"
                            .'• IF IT IS A QUESTION YOU CAN ANSWER WITHOUT ANY LOOKUP (e.g. about your own tools and capabilities): call answer_question NOW with the complete answer written out — do not describe what you are going to do, and do not refer to anything the user cannot see.'."\n"
                            .'• IF IT IS A CHANGE REQUEST (create/update): a title alone is enough to identify an entry — do NOT claim the target is ambiguous or unknown before searching. Check the catalog shortlist above, matching by title while ignoring case, punctuation and connective words (so "Salt Stone", "Salt and Stone" and "Salt & Stone" are the same). If it is there, call update_entry_job with its id; if not, call find_entries with the title first.'."\n"
                            .'Only reply "Cannot proceed:" if, after actually using the tools, the request genuinely cannot be fulfilled. '
                            .'Whatever you reply must be the message for the user itself — never a note about the request (no "no action needed", no "I will answer now", no "I have already answered").',
                    ];

                    continue;
                }

                Log::info('[entry-gen-tool] agentic planner done', [
                    'rounds' => $round + 1,
                    'fetches' => $runner->callCount('fetch_page_content'),
                    'entries_created' => $created,
                    'structural_writes' => $structuralWrites,
                ]);

                // Work-without-entries run (structural changes and/or asset
                // metadata, no entry jobs): a successful outcome — record the
                // model's own summary, enriched with the precise applied-changes
                // list so terse model summaries still carry the detail.
                if ($created === 0 && $structuralWrites > 0) {
                    $this->completePlannerAnswer(
                        $sessionId,
                        $this->withAppliedList(trim($content), $structuralChangesOut),
                        $round,
                        $created,
                    );

                    return;
                }

                // The model finished with plain text after doing real tool work,
                // or after the one-shot nudge: accept that text as its answer
                // instead of failing the run — turning "the model answered without
                // the answer_question tool" into an error only converts working
                // answers ("we have 467 assets") into error panels. Explicit
                // give-ups ("Cannot proceed:") keep failing loudly below. The
                // nudge above guarantees we can only get here once the model has
                // either used tools or already been pushed to act.
                if ($created === 0 && trim($content) !== ''
                    && ! str_starts_with(trim($content), 'Cannot proceed')) {
                    $this->completePlannerAnswer($sessionId, trim($content), $round, $created);

                    return;
                }

                if ($created === 0) {
                    throw new \RuntimeException(trim($content) !== ''
                        ? trim($content)
                        : (string) __('Planner ended without creating any entries.'));
                }

                return;
            }

            // Empty response: the model returned neither content nor a tool call
            // (a provider hiccup, an ignored forced tool, or an empty completion
            // after a fetch). Recover generically rather than hard-failing:
            //  - if entries were already dispatched, finish successfully;
            //  - otherwise nudge the model to act, bounded so we can't loop forever.
            if ($created > 0) {
                Log::info('[entry-gen-tool] agentic planner returned empty after dispatching; finishing', [
                    'rounds' => $round + 1,
                    'entries_created' => $created,
                ]);

                return;
            }

            if ($structuralWrites > 0) {
                // Work done but the model never produced a summary — finish
                // successfully with the applied-changes list rather than failing.
                $this->completePlannerAnswer(
                    $sessionId,
                    $this->withAppliedList((string) __('Done.'), $structuralChangesOut),
                    $round,
                    $created,
                );

                return;
            }

            $emptyResponses++;
            if ($emptyResponses <= self::MAX_EMPTY_RESPONSES) {
                // Capture why the completion was empty (e.g. finish_reason "length"
                // = the model hit the output-token cap) so the cause is diagnosable.
                Log::warning('[entry-gen-tool] agentic planner returned an empty response; nudging retry', [
                    'round' => $round + 1,
                    'attempt' => $emptyResponses,
                    'finish_reason' => is_array($choice) ? ($choice['finish_reason'] ?? null) : null,
                    'usage' => is_array($data) ? ($data['usage'] ?? null) : null,
                ]);

                $working[] = [
                    'role' => 'user',
                    'content' => 'Your last response was empty. Continue the task: either call a tool now '
                        .'(fetch_page_content to read a URL, find_entries / search_entry_content to locate an entry, '
                        .'create_entry_job / update_entry_job to act, or answer_question for a read-only question), '
                        .'or, if the request truly cannot be fulfilled, reply with a short message starting with "Cannot proceed:".',
                ];

                continue;
            }

            throw new \RuntimeException(__('The AI model returned an empty response. Please try again.'));
        }

        Log::error('[entry-gen-tool] agentic planner tool loop exceeded max rounds', [
            'max_rounds' => $maxRounds,
            'fetches' => $runner->callCount('fetch_page_content'),
            'entries_created' => $created,
        ]);

        if ($created === 0 && $structuralWrites === 0) {
            throw new \RuntimeException(__('Planner stopped: too many tool rounds.'));
        }

        if ($created === 0) {
            // Work happened before the round cap hit — still a success.
            $this->completePlannerAnswer(
                $sessionId,
                $this->withAppliedList((string) __('Done (stopped at the tool-round limit).'), $structuralChangesOut),
                $maxRounds - 1,
                $created,
            );

            return;
        }

        // Some entries were dispatched — surface a warning instead of failing the whole batch.
        $toolWarningsOut[] = __('Planner stopped after :n entries (too many tool rounds). Re-run if you need more.', ['n' => $created]);
    }

    /**
     * Append the precise applied-changes list to a completion summary, so even
     * a terse model summary tells the user exactly what was changed.
     *
     * @param  array<int, string>  $changes
     */
    private function withAppliedList(string $text, array $changes): string
    {
        if ($changes === []) {
            return $text;
        }

        return trim($text)."\n".__('Applied: :list.', ['list' => implode(', ', $changes)]);
    }

    /**
     * The one-line status appended (transiently) to each loop round so the model
     * always sees CURRENT progress and limits next to the newest message.
     */
    private function buildLoopStatusReminder(int $created, int $cap, int $structuralWrites, int $round, int $maxRounds, bool $advancedTools = false): string
    {
        $parts = [sprintf('Entries dispatched so far: %d of max %d.', $created, $cap)];

        if ($advancedTools) {
            $parts[] = 'Structural tools (collections/blueprints/fieldsets) are ENABLED this turn — earlier refusals in the conversation are outdated.';
        }

        if ($structuralWrites > 0) {
            $parts[] = sprintf('Structural changes applied: %d.', $structuralWrites);
        }

        $parts[] = sprintf('Tool rounds used: %d of %d.', $round, $maxRounds);

        $parts[] = $created >= $cap
            ? 'The entry cap is REACHED — do not dispatch more entries. Finish now with a short plain-text summary.'
            : 'When everything the newest user message asked for is done, finish with a short plain-text summary (no JSON, no tool calls).';

        return '[automatic status — not a message from the user] '.implode(' ', $parts);
    }

    /**
     * Effective per-request cap for this planning session. Prefers the value the
     * controller resolved from the CP user's role (persisted on the session);
     * falls back to the configured ceiling for older sessions without it.
     *
     * @param  array<string, mixed>  $session
     */
    private function resolvePlanCap(array $session): int
    {
        $configured = EntryCreationPolicy::configuredMaxPlanEntries();

        $sessionCap = $session['max_plan_entries'] ?? null;
        if (is_int($sessionCap) || (is_string($sessionCap) && is_numeric($sessionCap))) {
            return max(1, min($configured, (int) $sessionCap));
        }

        return $configured;
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
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{ok: bool, id?: string, count?: int, error?: string}
     */
    private function handleCreateEntryJobToolCall(string $argumentsJson, string $sessionId, array $catalog, int $cap, int $alreadyCreated, ?int $addCap = null): array
    {
        if ($this->batch === null || $this->decorator === null) {
            return ['ok' => false, 'error' => 'planner_dependencies_missing'];
        }

        if ($alreadyCreated >= $cap) {
            return ['ok' => false, 'error' => 'cap_reached', 'count' => $alreadyCreated];
        }

        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('[entry-gen-tool] agentic planner: invalid create_entry_job args', [
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        $collection = isset($args['collection']) && is_string($args['collection']) ? trim($args['collection']) : '';
        $blueprint = isset($args['blueprint']) && is_string($args['blueprint']) ? trim($args['blueprint']) : '';
        $entryPrompt = isset($args['prompt']) && is_string($args['prompt']) ? trim($args['prompt']) : '';
        $label = isset($args['label']) && is_string($args['label']) ? trim($args['label']) : '';

        if ($collection === '' || $entryPrompt === '') {
            return ['ok' => false, 'error' => 'missing_required_fields'];
        }

        $validated = $this->validateAndCoerceTarget($catalog, $collection, $blueprint);
        if ($validated === null) {
            return ['ok' => false, 'error' => 'invalid_target', 'collection' => $collection, 'blueprint' => $blueprint];
        }

        if ($label === '') {
            $label = Str::limit(strip_tags($entryPrompt), 60);
        }

        $decorated = $this->decorator->decorateOne([
            'collection' => $validated['collection'],
            'blueprint' => $validated['blueprint'],
            'prompt' => $entryPrompt,
            'label' => $label,
        ]);

        $added = $this->batch->addPlannedEntry($sessionId, $decorated, $addCap ?? $cap);
        if (! $added) {
            return ['ok' => false, 'error' => 'cap_or_session_rejected', 'count' => $alreadyCreated];
        }

        GeneratePlannedEntryJob::dispatch($sessionId, (string) $decorated['id']);

        return [
            'ok' => true,
            'id' => (string) $decorated['id'],
            'count' => $alreadyCreated + 1,
        ];
    }

    // ---------------------------------------------------------------------
    //  AI agent UPDATE tools — kept structurally separate from
    //  create_entry_job so removing/replacing update support is a clean delete:
    //  drop these three methods + the wiring in planAgenticToolLoop.
    // ---------------------------------------------------------------------

    /**
     * @return array{type: string, function: array<string, mixed>}
     */
    private function updateEntryJobToolDefinition(int $cap): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_entry_job',
                'description' => 'Queue an UPDATE to one existing entry. Use when the user wants to change, rewrite, fix, or extend an entry that already exists. You must provide the entry_id (from the catalog shortlist or from find_entries). The collection and blueprint are inferred from the entry. Counts toward the same '.$cap.'-call cap as create_entry_job.',
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
     * Normalize legacy OpenAI `function_call` payloads into `tool_calls`.
     *
     * @param  array<string, mixed>  $msg
     * @return array<string, mixed>
     */
    private function normalizeAssistantMessage(array $msg): array
    {
        $toolCalls = $msg['tool_calls'] ?? null;
        if (is_array($toolCalls) && $toolCalls !== []) {
            return $msg;
        }

        $fn = $msg['function_call'] ?? null;
        if (! is_array($fn)) {
            return $msg;
        }

        $name = isset($fn['name']) && is_string($fn['name']) ? $fn['name'] : '';
        if ($name === '') {
            return $msg;
        }

        $args = isset($fn['arguments']) && is_string($fn['arguments']) ? $fn['arguments'] : '{}';
        $msg['tool_calls'] = [[
            'id' => 'legacy_'.Str::uuid()->toString(),
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => $args],
        ]];

        return $msg;
    }

    /**
     * Parse models that print answer_question as assistant text instead of calling the tool.
     */
    private function extractAnswerQuestion(string $content): ?string
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $json = null;
        if (preg_match('/^answer_question\s*[:(]\s*(\{.*\})\s*\)?$/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/^\{\s*"answer"\s*:/s', $content)) {
            $json = $content;
        }

        if ($json === null) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['answer']) || ! is_string($decoded['answer'])) {
            return null;
        }

        $answer = trim($decoded['answer']);

        return $answer !== '' ? $answer : null;
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
                    ],
                    'required' => ['steps'],
                ],
            ],
        ];
    }

    /**
     * Terminal propose_plan outcome: record the plan (and optional question) as
     * a 'plan' assistant turn and pause the run. The CP renders it with an
     * approve action; the user's approval/answer arrives as a follow-up turn.
     *
     * @param  array<int, string>  $steps
     */
    private function completePlannerPlan(string $sessionId, array $steps, string $question, int $round): void
    {
        $lines = [];
        foreach (array_values($steps) as $i => $step) {
            $lines[] = ($i + 1).'. '.$step;
        }

        $text = __('Proposed plan:')."\n".implode("\n", $lines);

        if ($question !== '') {
            $text .= "\n\n".__('Question:').' '.$question;
        }

        $this->recordPlanTurn($sessionId, $text, $round);
    }

    /**
     * Record a proposed plan as the run's successful outcome (kind 'plan', so
     * the CP renders the approve action) — shared by the tool path and the
     * printed-as-text recovery path.
     */
    private function recordPlanTurn(string $sessionId, string $text, int $round): void
    {
        Log::info('[entry-gen-tool] agentic planner proposed a plan', [
            'rounds' => $round + 1,
        ]);

        $this->batch?->recordPlannerAnswer($sessionId, $text);
        $this->batch?->appendAssistantTurn($sessionId, $text, [], 'plan');
    }

    /**
     * Tool name when the model PRINTED a tool call as reply text
     * ("find_entries: …") instead of invoking it — protocol drift typical of
     * mid-tier models on long prompts. Generic over every registered tool.
     *
     * @param  array<int, string>  $toolNames  Lower-cased registered tool names
     */
    private function printedToolCallName(string $content, array $toolNames): ?string
    {
        if (preg_match('/^\s*([a-z0-9_]+)\s*[:({]/i', trim($content), $m) !== 1) {
            return null;
        }

        $name = strtolower($m[1]);

        return in_array($name, $toolNames, true) ? $name : null;
    }

    /**
     * Recover a propose_plan the model printed as assistant TEXT instead of a
     * tool call ("propose_plan: …" or "propose_plan:{\"steps\":[…]}").
     * Returns the human-readable plan text, or null when the content is not a
     * printed plan.
     */
    private function extractProposedPlan(string $content): ?string
    {
        $content = trim($content);

        if ($content === '' || preg_match('/^propose_plan\s*[:(]?\s*/i', $content, $m) !== 1) {
            return null;
        }

        $rest = trim(substr($content, strlen($m[0])), " \t\n\r)");

        if (str_starts_with($rest, '{')) {
            $decoded = json_decode($rest, true);

            if (is_array($decoded)) {
                $steps = array_values(array_filter((array) ($decoded['steps'] ?? []), 'is_string'));

                if ($steps !== []) {
                    $lines = [];
                    foreach ($steps as $i => $step) {
                        $lines[] = ($i + 1).'. '.$step;
                    }

                    $text = __('Proposed plan:')."\n".implode("\n", $lines);
                    $question = isset($decoded['question']) && is_string($decoded['question']) ? trim($decoded['question']) : '';

                    if ($question !== '') {
                        $text .= "\n\n".__('Question:').' '.$question;
                    }

                    return $text;
                }
            }
        }

        return $rest !== '' ? $rest : null;
    }

    private function completePlannerAnswer(string $sessionId, string $answer, int $round, int $created): void
    {
        Log::info('[entry-gen-tool] agentic planner answered a question', [
            'rounds' => $round + 1,
            'entries_created' => $created,
        ]);
        $this->batch?->recordPlannerAnswer($sessionId, $answer);
        $this->batch?->appendAssistantTurn($sessionId, $answer, [], 'answer');
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
     * @return array{ok: bool, results?: array<int, array{id: string, title: string, slug: string, collection: string, snippet: string}>, error?: string}
     */
    private function handleSearchEntryContentToolCall(string $argumentsJson): array
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        $query = isset($args['query']) && is_string($args['query']) ? trim($args['query']) : '';
        if ($query === '') {
            return ['ok' => false, 'error' => 'missing_query'];
        }

        $collection = isset($args['collection']) && is_string($args['collection']) ? trim($args['collection']) : null;
        if ($collection === '') {
            $collection = null;
        }

        $limit = isset($args['limit']) && is_numeric($args['limit']) ? (int) $args['limit'] : 10;

        return ['ok' => true, 'results' => $this->generator->searchEntryContent($collection, $query, $limit)];
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{ok: bool, id?: string, count?: int, error?: string, entry_id?: string, collection?: string}
     */
    private function handleUpdateEntryJobToolCall(string $argumentsJson, string $sessionId, array $catalog, int $cap, int $alreadyCreated, ?int $addCap = null): array
    {
        if ($this->batch === null || $this->decorator === null) {
            return ['ok' => false, 'error' => 'planner_dependencies_missing'];
        }

        if ($alreadyCreated >= $cap) {
            return ['ok' => false, 'error' => 'cap_reached', 'count' => $alreadyCreated];
        }

        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('[entry-gen-tool] update_entry_job invalid args', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        $entryId = isset($args['entry_id']) && is_string($args['entry_id']) ? trim($args['entry_id']) : '';
        $entryPrompt = isset($args['prompt']) && is_string($args['prompt']) ? trim($args['prompt']) : '';
        $label = isset($args['label']) && is_string($args['label']) ? trim($args['label']) : '';

        if ($entryId === '' || $entryPrompt === '') {
            return ['ok' => false, 'error' => 'missing_required_fields'];
        }

        $entry = \Statamic\Facades\Entry::find($entryId);
        if (! $entry) {
            return ['ok' => false, 'error' => 'entry_not_found', 'entry_id' => $entryId];
        }

        $collectionHandle = (string) $entry->collectionHandle();
        $blueprintHandle = (string) ($entry->blueprint()?->handle() ?? '');

        // Sanity-check that the entry's collection still exists in the catalog
        // we showed the LLM. Blueprint we trust from the entry itself.
        $catalogHandles = array_map(fn ($r) => $r['handle'] ?? '', $catalog);
        if (! in_array($collectionHandle, $catalogHandles, true)) {
            return ['ok' => false, 'error' => 'entry_collection_not_in_catalog', 'collection' => $collectionHandle];
        }

        if ($label === '') {
            $label = (string) ($entry->value('title') ?? Str::limit(strip_tags($entryPrompt), 60));
        }

        $decorated = $this->decorator->decorateOne([
            'collection' => $collectionHandle,
            'blueprint' => $blueprintHandle,
            'prompt' => $entryPrompt,
            'label' => $label,
            'entry_id' => $entryId,
        ]);

        $added = $this->batch->addPlannedEntry($sessionId, $decorated, $addCap ?? $cap);
        if (! $added) {
            return ['ok' => false, 'error' => 'cap_or_session_rejected', 'count' => $alreadyCreated];
        }

        GeneratePlannedEntryJob::dispatch($sessionId, (string) $decorated['id']);

        return [
            'ok' => true,
            'id' => (string) $decorated['id'],
            'entry_id' => $entryId,
            'collection' => $collectionHandle,
            'count' => $alreadyCreated + 1,
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

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{ok: bool, id?: string, count?: int, error?: string, message?: string, entry_id?: string, target_site?: string}
     */
    private function handleLocalizeEntryToolCall(string $argumentsJson, string $sessionId, array $catalog, int $cap, int $alreadyCreated, ?int $addCap = null): array
    {
        if ($this->batch === null || $this->decorator === null) {
            return ['ok' => false, 'error' => 'planner_dependencies_missing'];
        }

        if (! CpFeatures::translationsEnabled()) {
            return [
                'ok' => false,
                'error' => 'translations_disabled',
                'message' => 'Translations are disabled. Tell the user. Do not create a new entry on the current site as a substitute.',
            ];
        }

        if ($alreadyCreated >= $cap) {
            return ['ok' => false, 'error' => 'cap_reached', 'count' => $alreadyCreated];
        }

        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('[entry-gen-tool] localize_entry invalid args', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        $entryId = isset($args['entry_id']) && is_string($args['entry_id']) ? trim($args['entry_id']) : '';
        $target = isset($args['target_site']) && is_string($args['target_site']) ? trim($args['target_site']) : '';
        $label = isset($args['label']) && is_string($args['label']) ? trim($args['label']) : '';

        if ($entryId === '' || $target === '') {
            return ['ok' => false, 'error' => 'missing_required_fields', 'message' => 'entry_id and target_site are required.'];
        }

        $entry = \Statamic\Facades\Entry::find($entryId);
        if (! $entry) {
            return ['ok' => false, 'error' => 'entry_not_found', 'entry_id' => $entryId, 'message' => 'No entry with that id. Use find_entries, then call localize_entry again.'];
        }

        $collectionHandle = (string) $entry->collectionHandle();
        $blueprintHandle = (string) ($entry->blueprint()?->handle() ?? '');
        $catalogHandles = array_map(fn ($r) => $r['handle'] ?? '', $catalog);
        if (! in_array($collectionHandle, $catalogHandles, true)) {
            return ['ok' => false, 'error' => 'entry_collection_not_in_catalog', 'collection' => $collectionHandle];
        }

        $resolved = (new SiteLanguageResolver)->resolve($target);
        if (! isset($resolved['site'])) {
            return [
                'ok' => false,
                'error' => (string) ($resolved['error'] ?? 'unknown_site'),
                'message' => (string) ($resolved['message'] ?? 'Unknown target site.'),
            ];
        }

        $site = $resolved['site'];
        if ($site->handle() === (string) $entry->locale()) {
            return [
                'ok' => false,
                'error' => 'same_site',
                'message' => 'That entry is already on site '.$site->handle().'. localize_entry writes a localization on a different site. Do not create a new entry on the current site.',
            ];
        }

        $siteLabel = (new SiteLanguageResolver)->displayName($site);
        if ($label === '') {
            $originTitle = (string) ($entry->value('title') ?? $entry->slug() ?? $entryId);
            $label = trim($originTitle.' → '.$siteLabel);
        }

        $decorated = $this->decorator->decorateOne([
            'collection' => $collectionHandle,
            'blueprint' => $blueprintHandle,
            'prompt' => 'Localize into '.$site->handle().' and translate the source content there.',
            'label' => $label,
            'entry_id' => $entryId,
        ]);
        $decorated['operation'] = 'localize';
        $decorated['target_site'] = $site->handle();
        $decorated['target_locale'] = (string) $site->locale();
        $decorated['target_site_label'] = $siteLabel;

        $added = $this->batch->addPlannedEntry($sessionId, $decorated, $addCap ?? $cap);
        if (! $added) {
            return ['ok' => false, 'error' => 'cap_or_session_rejected', 'count' => $alreadyCreated];
        }

        GeneratePlannedEntryJob::dispatch($sessionId, (string) $decorated['id']);

        return [
            'ok' => true,
            'id' => (string) $decorated['id'],
            'entry_id' => $entryId,
            'target_site' => $site->handle(),
            'count' => $alreadyCreated + 1,
            'message' => 'Queued a localization of this entry on site '.$site->handle().'. The worker saves it there, linked to the source entry as its origin, and translates the source content into it.',
        ];
    }

    /**
     * @return array{ok: bool, results?: array<int, array<string, mixed>>, pagination?: array<string, int|bool>, note?: string, error?: string}
     */
    private function handleFindEntriesToolCall(string $argumentsJson): array
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        $query = isset($args['query']) && is_string($args['query']) ? trim($args['query']) : '';
        if ($query === '') {
            return ['ok' => false, 'error' => 'missing_query'];
        }

        $collection = isset($args['collection']) && is_string($args['collection']) ? trim($args['collection']) : null;
        if ($collection === '') {
            $collection = null;
        }

        $limit = isset($args['limit']) && is_numeric($args['limit']) ? (int) $args['limit'] : 10;
        $offset = isset($args['offset']) && is_numeric($args['offset']) ? (int) $args['offset'] : 0;

        return array_merge(['ok' => true], $this->generator->findEntries($collection, $query, $limit, $offset));
    }

    /**
     * @param  array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths}  $urlAug
     */
    private function urlAugmentationForResponse(array $urlAug, bool $appendedToPrompts): array
    {
        return [
            'appendix' => $urlAug['appendix'],
            'warnings' => $urlAug['warnings'],
            'preferred' => $urlAug['preferred'],
            'appended_to_prompts' => $appendedToPrompts,
        ];
    }

    /**
     * When the CP site locale is German, planner-written labels/prompts must avoid ß.
     */
    private function germanNoEszettPlannerRule(?string $siteLocale): string
    {
        if ($siteLocale === null || trim($siteLocale) === '') {
            return '';
        }

        $normalized = strtolower(str_replace('_', '-', trim($siteLocale)));

        if (! str_starts_with($normalized, 'de')) {
            return '';
        }

        return "- For any German in \"label\" or \"prompt\": NEVER use ß; always use ss (e.g. Strasse, gross, heiss).\n";
    }

    /**
     * Tolerant JSON parsing — accepts {entries:[…]}, a bare array, or a single object.
     *
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @param  string[]  $warnings
     * @return array<int, array{collection: string, blueprint: string, prompt: string, label: string}>
     */
    private function parseAndNormalize(string $raw, array $catalog, string $originalPrompt, array &$warnings): array
    {
        $response = trim($raw);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        $candidates = [];

        $balanced = JsonObjectExtractor::firstObject($response);
        if ($balanced !== null) {
            $candidates[] = $balanced;
        }

        $firstBrace = strpos($response, '{');
        $lastBrace = strrpos($response, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $slice = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);
            if ($balanced === null || $slice !== $balanced) {
                $candidates[] = $slice;
            }
        }

        $firstBracket = strpos($response, '[');
        $lastBracket = strrpos($response, ']');
        if ($firstBracket !== false && $lastBracket !== false && $lastBracket > $firstBracket) {
            $candidates[] = substr($response, $firstBracket, $lastBracket - $firstBracket + 1);
        }

        $decoded = null;
        foreach ($candidates as $jsonStr) {
            try {
                $decoded = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
                break;
            } catch (\JsonException) {
                continue;
            }
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException(__('Could not parse planner response.'));
        }

        $list = null;

        if (isset($decoded['entries']) && is_array($decoded['entries'])) {
            $list = $decoded['entries'];
        } elseif (array_is_list($decoded)) {
            $list = $decoded;
        } elseif (isset($decoded['collection']) || isset($decoded['blueprint'])) {
            $list = [$decoded];
        }

        if (! is_array($list) || $list === []) {
            return [];
        }

        $normalized = [];

        foreach ($list as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $collection = isset($row['collection']) && is_string($row['collection']) ? trim($row['collection']) : '';
            $blueprint = isset($row['blueprint']) && is_string($row['blueprint']) ? trim($row['blueprint']) : '';
            $entryPrompt = isset($row['prompt']) && is_string($row['prompt']) ? trim($row['prompt']) : '';
            $label = isset($row['label']) && is_string($row['label']) ? trim($row['label']) : '';

            $validated = $this->validateAndCoerceTarget($catalog, $collection, $blueprint);

            if ($validated === null) {
                $warnings[] = __('Skipped entry #:n: invalid collection or blueprint returned by the AI.', ['n' => $i + 1]);

                continue;
            }

            if ($entryPrompt === '') {
                $entryPrompt = $originalPrompt;
            }

            if ($label === '') {
                $label = Str::limit(strip_tags($entryPrompt), 60);
            }

            $normalized[] = [
                'collection' => $validated['collection'],
                'blueprint' => $validated['blueprint'],
                'prompt' => $entryPrompt,
                'label' => $label,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{collection: string, blueprint: string}|null
     */
    private function validateAndCoerceTarget(array $catalog, string $collection, string $blueprint): ?array
    {
        if ($collection === '') {
            return null;
        }

        foreach ($catalog as $row) {
            if (($row['handle'] ?? '') !== $collection) {
                continue;
            }

            $blueprints = $row['blueprints'] ?? [];
            $bpHandles = array_map(fn ($b) => $b['handle'] ?? '', $blueprints);

            if ($blueprint !== '' && in_array($blueprint, $bpHandles, true)) {
                return ['collection' => $collection, 'blueprint' => $blueprint];
            }

            if (! empty($bpHandles)) {
                return ['collection' => $collection, 'blueprint' => $bpHandles[0]];
            }
        }

        // Not in the catalog snapshot — check live. The catalog is captured
        // before the tool loop, so a collection the agent just created with the
        // advanced tools (create_collection + create_blueprint) is missing from
        // it even though entries can already be planned into it.
        $live = \Statamic\Facades\Collection::findByHandle($collection);
        if ($live) {
            $bpHandles = $live->entryBlueprints()
                ->reject->hidden()
                ->map(fn ($bp) => (string) $bp->handle())
                ->values()
                ->all();

            if ($blueprint !== '' && in_array($blueprint, $bpHandles, true)) {
                return ['collection' => $collection, 'blueprint' => $blueprint];
            }

            if ($bpHandles !== []) {
                return ['collection' => $collection, 'blueprint' => $bpHandles[0]];
            }
        }

        return null;
    }

    /**
     * Fallback when the planner fails: defer to the existing single-entry resolver.
     *
     * @param  string[]  $warnings
     * @param  array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths}  $urlAug
     * @param  array{appendix: string, warnings: array<int, string>}  $figmaAug
     * @return array{entries: array<int, array{collection: string, blueprint: string, prompt: string, label: string}>, warnings: string[], url_augmentation: array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths, appended_to_prompts: bool}}
     */
    private function singleEntryFallback(string $prompt, ?string $attachmentContent, array $warnings, array $urlAug, array $figmaAug = ['appendix' => '', 'warnings' => []]): array
    {
        $resolved = $this->generator->resolveTargetFromPrompt($prompt, $attachmentContent, $urlAug, $figmaAug);

        foreach ($urlAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        foreach ($figmaAug['warnings'] as $w) {
            $warnings[] = $w;
        }

        $combinedPrompt = trim($prompt.$urlAug['appendix'].$figmaAug['appendix']);

        return [
            'entries' => [[
                'collection' => $resolved['collection'],
                'blueprint' => $resolved['blueprint'],
                'prompt' => $combinedPrompt,
                'label' => Str::limit(strip_tags($prompt), 60),
            ]],
            'warnings' => $warnings,
            'url_augmentation' => $this->urlAugmentationForResponse($urlAug, true),
        ];
    }
}

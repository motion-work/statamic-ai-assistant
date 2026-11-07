# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`motion-work/statamic-ai-assistant` — a Statamic **6.5+** Control Panel addon (`3.x` line; Statamic 5 is not supported). It provides AI text fieldtypes, Bard AI buttons, DeepL translations (field, entry, bulk, navigation), a DeepL glossary/style-rules editor, and the **AI agent** (queued multi-entry content generation). See `CHANGELOG.md` for 2.x → 3.0 breaking changes.

## Commands

PHP (addon tested with Orchestra Testbench via `Statamic\Testing\AddonTestCase`):

```bash
composer install
vendor/bin/phpunit                                   # full suite
vendor/bin/phpunit tests/Services/TieredModelTest.php # one file
vendor/bin/phpunit --filter test_no_swap_when_fast_model_unset
```

- A suite run rewrites `tests/__fixtures__` (deletes and creates content files). Restore before committing: `git checkout -- tests/__fixtures__ && git clean -fd tests/__fixtures__`.
- Tests that sign in more than one user over CP routes need `config(['statamic.editions.pro' => true])`; otherwise Statamic throws "Statamic Pro is required for multiple users".
- Permissions added with `Permission::extend()` are only listed after `Permission::boot()`.
- A field without a `display` reports its handle in Title Case (`blocks` → `Blocks`).

Frontend (Node per `.nvmrc` = v22, pnpm). `@statamic/cms` resolves from `vendor/statamic/cms/resources/dist-package`, so **run `composer install` before `pnpm install`**:

```bash
pnpm install
pnpm dev     # Vite dev server (writes resources/dist/hot)
pnpm build   # outputs to resources/dist/build
```

- `pnpm-workspace.yaml` allow-lists the esbuild postinstall (pnpm 11 blocks dependency build scripts by default).
- Built assets in `resources/dist/build` are shipped with the package — rebuild after JS/CSS changes.
- Never leave `resources/dist/hot` around (gitignored); it makes the CP load from localhost:5173.
- Host apps must republish after upgrades: `php artisan vendor:publish --tag=statamic-ai-assistant --force` (assets) and `--tag=statamic-ai-assistant-config --force` (config).

### Quality gate

```bash
composer check   # what CI runs: Pint --test, Sloppy (new findings only), PHPUnit
composer format  # Pint (Laravel preset, pint.json)
composer fix     # Sloppy's automated fixes: Rector, then Pint on the rewritten files
```

- [Sloppy](https://github.com/heyosseus/sloppy) (`heyosseus/sloppy`, config `.sloppy.php`, analyses `src/`) flags code agents tend to leave behind: swallowed exceptions, god methods, nested ternaries, copy-paste drift, placeholder bodies. Findings that existed when it was adopted are in `.sloppy-baseline.json`; only new ones fail. Do not re-baseline to make a check pass. Fix the code, or say in your reply why a finding is deliberate.
- Claude Code runs it through the hooks in `.claude/settings.json`: after every edit (new findings in that file are reported back) and before finishing (new high findings block once). CI (`.github/workflows/ci.yml`) runs `sloppy ci`, which annotates new findings on pull requests.
- Sloppy's Laravel rules (SL2xx) are off: they assume Eloquent, while `Site`, `Entry` and `Taxonomy` here read config and the flat-file Stache.
- PHP runs out of memory analysing this codebase at the default 128M; hooks and composer scripts pass `-d memory_limit=1G`. Run it the same way by hand.
- Do not run `sloppy agents install` again: it re-adds hooks without the memory flag and appends its generic rule list to this file.

## Architecture

### Wiring
- `src/ServiceProvider.php` is the single wiring point: registers fieldtypes, the `TranslateEntry` action, CP routes (`routes/cp.php` plus legacy `/prompt*` routes registered inline), CP nav items, and the bindings: the services in `SINGLETONS` are shared and autowired from their constructors, `AbstractAiService` is chosen by config, and the connector bridge is built per request. A new shared service goes into `SINGLETONS`; only one whose constructor takes scalars or needs a choice gets a closure.
- `AbstractAiService` is bound via `Services/Ai/AiProviders` to `OpenRouterService` or `InfomaniakService` (Swiss hosting). `provider_name=auto` (default, also any unknown name) picks OpenRouter once `OPENROUTER_API_KEY` is set, else Infomaniak. Both extend `Ai/OpenAiCompatibleService` over `Ai/OpenAiCompatibleClient`, which owns the retry policy (transient = 429/5xx/dropped connection; timeouts and 402/403 budget errors deliberately **not** retried).
- Every call runs as an `AiTask` (agent, light, field, translate, vision) set with the scoped `forTask()`; agent is the default and the only task that keeps reasoning on. OpenRouter reads each task's model list (first answers, rest are fallbacks), reasoning effort and preset from `openrouter_profiles`; Infomaniak maps tasks onto `infomaniak_model` / `_fast` / vision model. `usingFastModel()` is `forTask(Light)`. `withRequestOptions()` adds payload fields per call (`response_format`, `session_id`, …; Infomaniak keeps only `response_format`), `withCompletionContext()` tags the `AiCompletionFinished` event that `Usage/` logs.
- OpenRouter routing gotchas, found against the live API: `require_parameters` is sent only with `response_format` (with tools, temperature or reasoning it filters out every Anthropic endpoint), structured-output requests drop `temperature`, quick-task models must accept reasoning effort `none`, and Claude thinking models reject a forced `tool_choice`, so they sit last in the agent list. Tool loops must send `reasoning_details` back unchanged (`Support/AssistantToolCallMessage`).
- Values passed to JS via `Statamic::provideToScript` are **closures** so they resolve per request — `User::current()` is null at boot. Keep it that way for anything role-dependent.

### Access rules
- `entry_generator` is the master switch for the AI agent; `enable_agent_for_editors` only gates non-super users (super admins always have access). `EntryCreationPolicy` caps entries per request for editors (`editor_limit_entries`).
- Claude connector levels (`Support/ClaudeAccess`): read ⊂ edit ⊂ publish, granted per role on the access page, where a user's own level overrides their roles' (`claude_*` in `access.yaml`, `claude_none` for users) and enforced per tool in `TranslationMcpServer::TOOL_LEVELS`, on top of Statamic's policies. They apply only while `claude_restricted` is on (default off, so existing users keep access); super admins and stdio are never restricted.
- Bulk translations and AI agent settings nav are super-admin only; Glossary & style rules is visible to all CP users; changing it (CP page and Claude connector) needs the `edit translation glossary` permission (`Support/GlossaryAccess`), super admins always.

### AI agent (entry generation)
Flow: `EntryGeneratorController::generateStream` → `EntryGenerationBatchService::initPlanningSession` → `PlanEntriesJob` → `EntryGenerationPlanner` (agentic mode: LLM calls a `create_entry_job` tool to append plan rows) → one `GeneratePlannedEntryJob` per entry → `EntryGeneratorService`, which orchestrates one entry's generation over:
  - `FieldSchemaBuilder` (blueprint → field schema for the model; `ReferenceFieldSchema`, `SetLayoutCatalog` and `FieldTypeGuidance` describe reference fields, blocks and per-type rules) and `GenerationPrompt` (system/user messages), with `GenerationSourceContext` appending fetched URL text and Figma frames;
  - `EntryCompletion` (the `GenerationToolLoop` with the model's fetch/read/image tools, or one plain completion) and `EntryJsonParser` (reply JSON, one repair retry);
  - `FieldValueMapper` → `ScalarValueMapper`, `BardValueMapper`, `ReferenceValueMapper` (terms/entries/forms), `AssetFieldFiller` and `RequiredFieldFallback` (the model's JSON → storable field data);
  - `FieldOptionCatalog` (choice options, taxonomy terms; shared per request), `EntrySearch` (titles; `EntryContentSearch` for field text), `GenerationTargets` (catalog, target from prompt), `UpdateAssetFields` (images on the update path); `FieldTypes` groups the fieldtypes.
  - `EntryWriter` saves the result (new draft, or an update merged over the entry); `EntryComposer` is the part the Claude connector reuses.
- The planner runs `AgenticPlannerLoop`; `AgenticPlannerPrompt` builds its system prompt, `PlannerToolbox` its read tools, `PlannerJobTools` the job tools (`EntryJobTools` for create/update, `LocalizeEntryJobTool`) and search, `PlannerReplyParser` / `AgenticReplyHandler` recover tool calls printed as text, `PlannerTranscript` records turns, and `AgenticPlanRun` carries one turn's state.
- `Support/BardHtmlParser` converts HTML to Bard (it keeps text in paragraphs and drops alignment, which Statamic's own tiptap converter does not), `BardButtonSanitizer` / `BardInlineSanitizer` trim nodes to a field's buttons; the agent's mapper and the translator both use them.
- Session state lives in the **cache** (not DB), mutated only through `EntryGenerationBatchService::update()` under a `Cache::lock` (its planning and job API is in the `Concerns/TracksPlanning` and `TracksEntryJobs` traits). The browser polls `generate-progress/{sessionId}`; streaming output is recorded as deltas into the session. A real queue worker and a lock-capable cache are required outside tests.
- Tool calling: `src/Tools/` — each `ChatTool` is a self-contained class (name, OpenAI-style definition, handler, `maxCalls`), dispatched by `ChatToolRunner` with a shared `ToolContext`. Both the generator loop and the planner use the same runner.
- Context sources: `PromptUrlFetcher` (Jina reader, `prompt_url_fetch` config) + `HtmlReadableExtractor`, `FigmaContentFetcher` (Figma OAuth, tokens in `FigmaTokenStore`), `RemoteImageFetcher`/`AssetImageDownloader`, and editor-authored hints from `SetHintsService` (YAML at `set_hints_path`).

### Claude connector (`/mcp`, `src/Mcp/`)
The Claude app signs in with any CP account, then reads, translates, edits, duplicates, publishes and composes entries, each gated by Statamic's own policies for that account. Every write is a preview plus a confirmed save, except the service-account-only setup tools (`write_site_instructions`, `set_style_rules`, `install_package`, reached with a `ServiceTokens` bearer token while `mcp_service_tokens` is on; contract in `docs/service-accounts.md`). Before changing anything in `src/Mcp/`, the connector tools, or the parts of `EntryGeneratorService` (and the classes it delegates to, `EntryComposer` above all) / `FieldTextCollector` they reuse, read `docs/claude-connector.md`: it covers the permission table, the preview/confirm contract, why refactors keep stored blocks by id, where the connector deliberately differs from the agent, and how to verify a change against a host app.

### Translations (DeepL; LLM only as fallback)
- Every translation path funnels through `DeeplService::translateBatch()`. Without `DEEPL_API_KEY` (and `translation_llm_fallback` on) it hands the batch to `LlmTranslator` (JSON-in/JSON-out prompt with glossary hits + style rules, per-text retry on a malformed batch). `DeeplService::llmFallbackNotice()` is what controllers/actions surface to editors.
- `DeeplService` wraps `deeplcom/deepl-php` (`DeeplLanguages` maps locales, `DeeplRequestPlanner` builds glossary/style options, `DeeplUsage` reads the quota); `EntryTranslator` + `FieldTextCollector` (with `TranslationSegmentBuffer` and `LocalizablePolicy`) walk field data (Bard, replicator, grids), swapping each text for a placeholder and back, and `LocalizationWriter` saves the localization for DeepL and Claude translations alike. Placeholders are arrays (`['__segment__' => n]`, `['__bard_merged__' => n]`) because entry data holds real integers (Bard heading levels, number fields) that a bare-int placeholder would overwrite; `EntryReferenceResolver` remaps linked entry IDs and may recursively translate linked entries up to `linked_entries_max_depth` (0–5, default 1).
- `CpTranslationBatchRunner` picks sync vs queued (`TranslateEntryJob` on the `translations` queue) by `translation_mode` / `translation_sync_threshold`, and orders the selection with `ReferenceOrder`. `TranslationBatchCompletion` dispatches queued batches and writes their summary (while a batch runs, `getBatchProgress()` counts finished jobs from Laravel's job batch, whose id sits next to the progress record; status rows are written under a cache lock because workers share one row list); it is static because the callbacks are serialized with the batch, and the runner keeps its old `finishBatch` names so batches queued before an upgrade still finish. `TranslationService`'s batch progress, overwrite conflicts, term translation and reconcile pass are traits in `Services/Concerns/`. `NavigationTreeSyncService` syncs nav trees across sites over `NavigationTree` (reads) and `NavigationBranchMapper`.
- Glossary and style rules are YAML files under `content/statamic-ai-assistant/` (`TranslationGlossaryService`, `TranslationStyleRulesService`), synced to DeepL v3 and namespaced by `APP_NAME` — shared DeepL accounts need a unique `APP_NAME` per site. Style rules are arrays per language (legacy single strings are normalized on read); `prefer_glossary_over_style` splits segments so glossary terms stay enforced.

### Frontend
- `resources/js/app.js` registers all Vue 3 components in `Statamic.booting` (fieldtype components follow the `<handle>-fieldtype` naming) and extends Bard with AI/translation buttons (`AiBardService`, `AiTextLegacyBardNode`).
- CP pages are Blade views in `resources/views/` that mount a registered Vue page component (e.g. `entry-generator-page`). Agent UI state is in `resources/js/store/entryGeneratorStore.js`.
- CP strings use JSON translations in `resources/lang/` (en, de, de_CH, fr, it); add new keys to every file. In JS, resolve them from `Statamic.initialConfig.translations` (see `cpTranslate` in `app.js`) rather than importing Statamic's translator.

### Config
All behaviour is env-driven via `config/statamic-ai-assistant.php` (heavily commented — read it before adding options) and `config/deepl.php`. New config keys must be documented there and, if user-facing, in `README.md`/`CHANGELOG.md`, since host apps only get them after republishing config.

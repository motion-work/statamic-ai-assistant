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

No linter/formatter is configured.

## Architecture

### Wiring
- `src/ServiceProvider.php` is the single wiring point: registers fieldtypes, the `TranslateEntry` action, CP routes (`routes/cp.php` plus legacy `/prompt*` routes registered inline), CP nav items, and binds nearly every service as a singleton with explicit constructor dependencies. When adding a service or a constructor dependency, update the binding here.
- `AbstractAiService` is bound to `InfomaniakService` (default) or `GroqService` based on `provider_name`. Both speak OpenAI-style chat completions; the base class owns retry policy (transient = 429/5xx/dropped connection; timeouts deliberately **not** retried) a fast/strong model tier (`usingFastModel()` temporarily swaps the config model, e.g. `infomaniak_model_fast`), and `withoutThinking()` for quick tasks (Infomaniak sends `chat_template_kwargs` to skip reasoning; never for Mistral models, which return 400 on it). `usingFastModel()` implies `withoutThinking()`; agent planning/generation must keep thinking on.
- Values passed to JS via `Statamic::provideToScript` are **closures** so they resolve per request — `User::current()` is null at boot. Keep it that way for anything role-dependent.

### Access rules
- `entry_generator` is the master switch for the AI agent; `enable_agent_for_editors` only gates non-super users (super admins always have access). `EntryCreationPolicy` caps entries per request for editors (`editor_limit_entries`).
- Bulk translations and AI agent settings nav are super-admin only; Glossary & style rules is visible to all CP users; changing it (CP page and Claude connector) needs the `edit translation glossary` permission (`Support/GlossaryAccess`), super admins always.
- Claude connector (`/mcp`, `src/Mcp/`): any CP account signs in; each tool then follows Statamic's entry policy for that account (`view` to read, `edit` to edit in place or to write a localization, `create` to duplicate via Statamic's `DuplicateEntry` action, `publish` to publish/unpublish, checked on the unsaved localization so target-site access counts).

### AI agent (entry generation)
Flow: `EntryGeneratorController::generateStream` → `EntryGenerationBatchService::initPlanningSession` → `PlanEntriesJob` → `EntryGenerationPlanner` (agentic mode: LLM calls a `create_entry_job` tool to append plan rows) → one `GeneratePlannedEntryJob` per entry → `EntryGeneratorService` (the large core: blueprint/field schema building, LLM generation, asset/link resolution).
- Session state lives in the **cache** (not DB), mutated only through `EntryGenerationBatchService::update()` under a `Cache::lock`. The browser polls `generate-progress/{sessionId}`; streaming output is recorded as deltas into the session. A real queue worker and a lock-capable cache are required outside tests.
- Tool calling: `src/Tools/` — each `ChatTool` is a self-contained class (name, OpenAI-style definition, handler, `maxCalls`), dispatched by `ChatToolRunner` with a shared `ToolContext`. Both the generator loop and the planner use the same runner.
- Context sources: `PromptUrlFetcher` (Jina reader, `prompt_url_fetch` config) + `HtmlReadableExtractor`, `FigmaContentFetcher` (Figma OAuth, tokens in `FigmaTokenStore`), `RemoteImageFetcher`/`AssetImageDownloader`, and editor-authored hints from `SetHintsService` (YAML at `set_hints_path`).

### Translations (DeepL; LLM only as fallback)
- Every translation path funnels through `DeeplService::translateBatch()`. Without `DEEPL_API_KEY` (and `translation_llm_fallback` on) it hands the batch to `LlmTranslator` (JSON-in/JSON-out prompt with glossary hits + style rules, per-text retry on a malformed batch). `DeeplService::llmFallbackNotice()` is what controllers/actions surface to editors.
- `DeeplService` wraps `deeplcom/deepl-php`; `EntryTranslator` + `Concerns/TranslatesFields` walk field data (Bard, replicator, grids); `EntryReferenceResolver` remaps linked entry IDs and may recursively translate linked entries up to `linked_entries_max_depth` (0–5, default 1).
- `CpTranslationBatchRunner` picks sync vs queued (`TranslateEntryJob` on the `translations` queue) by `translation_mode` / `translation_sync_threshold`. `NavigationTreeSyncService` syncs nav trees across sites.
- Glossary and style rules are YAML files under `content/statamic-ai-assistant/` (`TranslationGlossaryService`, `TranslationStyleRulesService`), synced to DeepL v3 and namespaced by `APP_NAME` — shared DeepL accounts need a unique `APP_NAME` per site. Style rules are arrays per language (legacy single strings are normalized on read); `prefer_glossary_over_style` splits segments so glossary terms stay enforced.

### Frontend
- `resources/js/app.js` registers all Vue 3 components in `Statamic.booting` (fieldtype components follow the `<handle>-fieldtype` naming) and extends Bard with AI/translation buttons (`AiBardService`, `AiTextLegacyBardNode`).
- CP pages are Blade views in `resources/views/` that mount a registered Vue page component (e.g. `entry-generator-page`). Agent UI state is in `resources/js/store/entryGeneratorStore.js`.
- CP strings use JSON translations in `resources/lang/` (en, de, de_CH, fr, it); add new keys to every file. In JS, resolve them from `Statamic.initialConfig.translations` (see `cpTranslate` in `app.js`) rather than importing Statamic's translator.

### Config
All behaviour is env-driven via `config/statamic-ai-assistant.php` (heavily commented — read it before adding options) and `config/deepl.php`. New config keys must be documented there and, if user-facing, in `README.md`/`CHANGELOG.md`, since host apps only get them after republishing config.

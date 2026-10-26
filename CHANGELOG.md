# Changelog

All notable changes to **motion-work/statamic-ai-assistant** are documented here.

## Unreleased — Motion Work rebrand

Rebranded from [`bold-web/statamic-ai-assistant`](https://github.com/boldwebdev/statamic-ai-assistant) (3.0.0). There are no functional changes.

### Breaking / migration

- **Composer package:** `bold-web/statamic-ai-assistant` → `motion-work/statamic-ai-assistant`.
- **PHP namespace:** `BoldWeb\StatamicAiAssistant\` → `MotionWork\StatamicAiAssistant\`.
- **Feature name:** "BOLD agent" → "AI agent" (CP UI and translations; DE "KI-Agent", FR "Agent IA").
- **Config keys:** `bold_agent_settings_nav`, `bold_agent_max_plan_entries`, `bold_agent_asset_listing_cap`, `bold_agent_catalog_entries_shortlist` → `agent_*`. Republish config with `--force`.
- **Env vars:** `STATAMIC_AI_ASSISTANT_BOLD_AGENT_*` → `STATAMIC_AI_ASSISTANT_AGENT_*` (the old names are still read as fallbacks).
- **Fetched images folder default:** `bold-agent-fetched` → `ai-agent-fetched` (set `STATAMIC_AI_ASSISTANT_IMAGE_FETCH_FOLDER=bold-agent-fetched` to keep the old one).
- **CSS hooks:** `.bold-ai-statamic-assistant` → `.ai-statamic-assistant` and `.ai-bold-wrapper` → `.ai-assistant-wrapper`, which matters if you override styles.
- Republish assets with `php artisan vendor:publish --tag=statamic-ai-assistant --force`.

---

## 3.0.0 — 2026-07-06

Major release with CP, DeepL, and AI agent changes. **Review the upgrade steps below before updating from 2.x.**

### Added

- **AI agent** floating CP launcher with queued multi-entry generation (planner + background jobs).
- **AI agent settings** CP page: per-block hints and per-blueprint-field hints (hero, lead, …).
- **DeepL glossary & style rules** CP page (all CP users): YAML-backed term table + per-language style instructions synced to DeepL v3.
- Glossary/style **segment splitting** so glossary terms are hard-enforced even when a style rule is active (`prefer_glossary_over_style`, default `true`).
- **`ENABLE_AGENT_FOR_EDITORS`**: hide the AI agent from non-super editors while super admins always keep access.
- **`EDITOR_LIMIT_ENTRIES`**: cap non-super users to one entry per agent request (config `editor_limit_entries`).
- Style rules stored as an **array of instructions per language** in `translation-style-rules.yaml` (legacy single-string values are normalized on read).
- DeepL style-rule **reconcile-by-name** sync (cleans duplicate/orphan rules on the account).

### Changed

- **Website migration mode removed** from the CP (routes, nav, and migration UI). Use the AI agent URL-copy flow instead.
- **Bulk translations** and **AI agent settings** nav items are **super-admin only**. Glossary & style rules remain visible to all CP users.
- **`entryGeneratorEnabled`** and related CP script vars are resolved **per request** (closures), not at application boot — fixes role-based visibility.
- Published addon assets must be refreshed with `--force` after upgrades (manifest can otherwise point at stale JS).

### Breaking / upgrade required

1. **Composer constraint:** require `bold-web/statamic-ai-assistant:^3.0`.
2. **Republish config** (existing files are not overwritten by default):

   ```bash
   php artisan vendor:publish --tag=statamic-ai-assistant-config --force
   ```

   New / important keys in `config/statamic-ai-assistant.php`:

   - `enable_agent_for_editors` ← `ENABLE_AGENT_FOR_EDITORS` (default `true`)
   - `translation_glossary_path`, `translation_style_rules_path`
   - `prefer_glossary_over_style`, `editor_limit_entries`, `bold_agent_settings_nav`

3. **Republish assets:**

   ```bash
   php artisan vendor:publish --tag=statamic-ai-assistant --force
   ```

4. **DeepL:** translation features require `DEEPL_API_KEY`. Optional glossary/style YAML under `content/statamic-ai-assistant/` (created on first save from the CP).

5. **Style rules YAML:** if you had a single string per language under `rules.{lang}.instructions`, it still loads — but saving from the CP writes an array. Empty instructions delete the rule on DeepL.

6. **Multi-tenant DeepL accounts:** glossaries and style rules are namespaced by `APP_NAME`. Use a **unique `APP_NAME` per client** on shared DeepL accounts to avoid cross-site style-rule reconciliation.

### Removed

- CP **website migration** tool and related routes.

---

## 2.x

See git history / tags before `3.0.0` for the 2.x line (Statamic 6.5+, Bard AI, DeepL translations, entry generator foundations).

# Changelog

All notable changes to **motion-work/statamic-ai-assistant** are documented here.

## Unreleased

### Added

- **AI translation fallback:** without `DEEPL_API_KEY`, every translation path (field/Bard, Translate entry action, bulk, terms, navigation) runs on the configured AI model instead of failing with "DeepL API key is not configured". Editors are told each time: an info toast after field/Bard translations, a note in the entry action message (whose label becomes "Translate (AI)"), and an info card on the bulk translations page. Glossary terms that occur in the text and per-language style rules are passed to the model; HTML (Bard) markup is preserved; Swiss German targets use "ss" instead of "ß". New config `translation_llm_fallback` (`STATAMIC_AI_ASSISTANT_TRANSLATION_LLM_FALLBACK`, default `true`).

### Changed

- **AI features need an Infomaniak token:** an empty `INFOMANIAK_API_TOKEN` hides the agent, field buttons, AI instructions and agent access. Translation UI stays only when `DEEPL_API_KEY` is set, or when the token is set and the LLM translation fallback is on.
- **AI agent localizes instead of duplicating:** asking the agent to translate an existing entry (for example “übersetze diese Seite auf English” / “erstelle den EN-Eintrag”) localizes that source entry onto the target site and translates it there. It no longer creates a separate entry on the site that is open in the Control Panel.
- **Quick tasks skip reasoning:** Ai Text / Bard generate and refactor, alt text, image analysis and fast-tier helpers now ask Infomaniak reasoning models (Kimi K2.6, Qwen3.5/3.8) to answer without thinking first (≈0.5–3s instead of ≈13–16s, and no more empty replies when `max_tokens` runs out during thinking). The AI agent's planning and entry generation keep thinking on. New config `quick_tasks_without_thinking` (`STATAMIC_AI_ASSISTANT_QUICK_TASKS_WITHOUT_THINKING`, default `true`). Mistral models are skipped automatically, since they reject the switch.
- **Planner without URL tool:** uses `entry_generator_planner_max_output_tokens` instead of a fixed 1024 tokens, so reasoning models no longer fall back to a single entry.

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

1. **Composer constraint:** require `motion-work/statamic-ai-assistant:^3.0`.
2. **Republish config** (existing files are not overwritten by default):

   ```bash
   php artisan vendor:publish --tag=statamic-ai-assistant-config --force
   ```

   New / important keys in `config/statamic-ai-assistant.php`:

   - `enable_agent_for_editors` ← `ENABLE_AGENT_FOR_EDITORS` (default `true`)
   - `translation_glossary_path`, `translation_style_rules_path`
   - `prefer_glossary_over_style`, `editor_limit_entries`, `agent_settings_nav`

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

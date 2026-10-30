# Changelog

All notable changes to **motion-work/statamic-ai-assistant** are documented here.

## Unreleased

### Added

- **Claude app connector:** every install exposes `{APP_URL}/mcp`. Any Control Panel account can sign in, whatever its email domain. An address that is not a Control Panel account is refused. Claude translates with its own account. Saves are committed through Statamic git automation when that is enabled.
- **Edit entries from the Claude app:** two new connector tools, `preview_entry_edit` and `save_entry_edit`, change the text of an existing entry in place, in any language. The signed-in account's Control Panel role decides which entries it may edit (for example the Editor role). Claude shows each changed text before and after and saves only after the user agrees. Untouched text and the published state stay as they were. Besides text, Claude can change toggles, choice fields (select, button group, radio, checkboxes) and links, at the top of the entry and inside replicator, grid and component blocks; each is listed with its label and location ("Komponenten › tiles 2 › Kacheln › row 1"), a choice must be one of the field's options, and a link must be a URL, `mailto:`, `tel:`, a `/path`, a `#anchor` or `entry::<id>` of an existing entry. The slug changes only when Claude passes a new one: it is formatted like the Control Panel slug field, a slug another entry in the same collection and site already has is refused, and the preview warns that the web address changes. The save fires Statamic's normal events and records the account as `updated_by`. If the entry changed after the preview, nothing is saved.
- **Compose and refactor entries from the Claude app:** `get_content_model` gives Claude the same brief the AI agent works from (every field and content block with the editors' block hints from the AI block hints page, site instructions, glossary and style rules), so it can build a new entry from a goal and the user's content, or restructure an existing one. `preview_compose_entry` maps the composed JSON with the agent's own mapping, validates it against the blueprint (a missing required field is reported, not filled with a placeholder) and returns an outline; `save_compose_entry` saves after the user agrees. New entries are unpublished and placed in the page tree (optionally under a parent); a refactor goes in place or into an unpublished copy, as the user chooses. Blocks sent back with their stored id are kept exactly as stored apart from the fields Claude changed, so images, entry references and a disabled state survive. A library image only fills a required image field left empty. Creating needs the collection's create permission, in place the edit permission.
- **Images from the Claude app:** Claude can replace an image on a page or put one into an empty image field, at the top of an entry or inside a block, for example with a picture the user attached in the chat. `prepare_image_upload` returns a one-time upload link (30 minutes, one image, JPG/PNG/GIF/WebP/AVIF/SVG up to `image_fetch.max_bytes`) because Claude cannot pass attachments to a connector: the user opens it and drops the image, or Claude posts it with curl when it can. The image goes into the container and folder Claude names, with the alt text Claude writes; an existing file is never overwritten. It needs the role's “Upload assets” permission for that container, checked when the link is made and on upload. `check_image_upload` returns the stored path, which Claude sets through `preview_entry_edit` / `save_entry_edit` like any other value: assets fields are now listed there as kind `asset` with their container and folder, and take only assets that exist in that container (and folder, when the field is restricted). Block locations now include each block's heading (`Komponenten › tiles 4 › Kacheln › row 2 "Bankkaution"`), so Claude finds the section the user names.
- **Claude access per role and user:** the super-admin page “AI agent access” is now **AI & Claude access** and is shown even without AI features. With “Restrict Claude connector access” on, roles and individual users get a level for the Claude app independent of what they may do in the Control Panel: *read*, *edit* (also translate, compose, duplicate, upload images, change the glossary) or *publish*. Tools above the level are hidden and refused, and an account without a level cannot sign in. The Control Panel role still applies on top. Off by default, so nothing changes until a super admin turns it on. Stored in `access.yaml` (`claude_restricted`, `claude_read`, `claude_edit`, `claude_publish`).
- **Browse the asset library from the Claude app:** `list_assets` lists the files (path, type, size, alt text) and subfolders of a folder, or searches file names below it. It needs the role's “View assets” permission for the container, as in the Control Panel.
- **Fewer tokens for the Claude app:** tool results are compact JSON instead of pretty-printed (30–40% smaller). The composing brief now comes in two steps: `get_content_model` carries the block catalog with every description and when-to-use rule but no block fields, and the new `get_block_schemas` returns the fields of the blocks Claude chose (a refactor gets the fields of the blocks already on the page). Field descriptions repeated across fields are written once as numbered notes. Composing a typical page drops from about 80,000 to under 40,000 characters.
- **Link fields accept anchors, `mailto:` and `tel:`** in AI agent output, as the link fieldtype does.
- **Duplicate, publish and unpublish from the Claude app:** `duplicate_entry` runs Statamic's own Duplicate action as the signed-in account (an unpublished copy with "(Duplicated)" and a new slug, in the same place in the page tree, plus the localizations the account may create); it needs the collection's create permission on that site. `set_published` publishes or unpublishes one entry in one language and needs the publish permission. Both describe the change first and act only with `confirmed: true`.
- **Claude connector follows the Control Panel role:** entries are listed and read only when the account may view them, and a localization is saved only when the account may edit it, including access to the target site. A preview can only be confirmed by the account that made it.
- **Translations survive a deploy:** entry translations are saved quietly (so relationship sync does not rewrite the source), then queued for Statamic git when git automation is on. Linked localizations from the same run share that one commit.
- **AI translation fallback:** without `DEEPL_API_KEY`, every translation path (field/Bard, Translate entry action, bulk, terms, navigation) runs on the configured AI model instead of failing with "DeepL API key is not configured". Editors are told each time: an info toast after field/Bard translations, a note in the entry action message (whose label becomes "Translate (AI)"), and an info card on the bulk translations page. Glossary terms that occur in the text and per-language style rules are passed to the model; HTML (Bard) markup is preserved; Swiss German targets use "ss" instead of "ß". New config `translation_llm_fallback` (`STATAMIC_AI_ASSISTANT_TRANSLATION_LLM_FALLBACK`, default `true`).

### Fixed

- **Numbers survive translations:** translating an entry no longer overwrites Bard heading levels and integer fields whose value matched a text's position (a level-2 heading could become the text of the second segment). This affected DeepL, the AI fallback and the Claude connector.

### Changed

- **Changing the glossary and style rules needs a role permission:** new Statamic permission “Edit translation glossary and style rules” (`edit translation glossary`, under Users → Roles, group “AI Assistant”). Every Control Panel user can still read them; without the permission the page is read-only and the Claude connector's `save_glossary` and `save_style_rules` refuse. Super admins always may. Grant it to the roles that maintain terminology (for example Editor) after upgrading, since they apply to every translation on the site.

- **German sidebar label:** “Massenübersetzungen” is now “Übersetzungen”.
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

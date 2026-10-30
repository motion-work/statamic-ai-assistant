# Claude connector (MCP)

The site exposes `{APP_URL}/mcp` for the Claude app. Claude writes the text; the addon only reads Statamic, describes a change, and saves what Claude sends back after the user agrees. DeepL and Infomaniak are never called from here.

## Request flow and sign-in

- Routes live in `routes/web.php`: `/mcp`, `/.well-known/oauth-*`, `/mcp/oauth/{register,authorize,token}`, `/mcp/upload/{token}`.
- `McpOAuth`: OAuth with PKCE and dynamic client registration; clients, codes and tokens live in the cache (access 12 h, refresh 30 days). Any Control Panel account signs in; there is no email-domain gate and no shared token.
- The sign-in form carries a CSRF token and is throttled to 10 posts per minute. A script driving it fetches the form first (cookie jar + `_token`), and a 429 means wait a minute.
- `McpController` → `TranslationMcpServer::dispatch()` (JSON-RPC, tool list and schemas) → `ClaudeTranslationBridge` (all behaviour).
- `ClaudeTranslationBridge::setActor()` also calls `auth()->setUser()`, so Statamic policies, `updated_by`, git commit authors and Statamic's own actions (`DuplicateEntry`) see the signed-in account. `bin/mcp` (stdio) has no actor: it reads and translates, and every write that needs an account is refused.
- Tool results are compact JSON; only the model reads them.

## Permissions

Two layers. First, a super admin may give each role or user a Claude level on the **AI & Claude access** page (`Support/ClaudeAccess`): read ⊂ edit ⊂ publish. `TranslationMcpServer::TOOL_LEVELS` names the level each tool needs (unlisted tools need read; previews of a write need the write's level). `tools/list` hides what the account may not use and `tools/call` refuses it, so a grant revoked mid-session takes effect on the next call; sign-in needs read, and the upload link checks edit again on upload. The levels only apply while `claude_restricted` is on in `access.yaml`; it is off by default so an update locks nobody out. Super admins and the stdio server are never restricted. When adding a write tool, add it to `TOOL_LEVELS`.

Second, every tool follows Statamic's own policies for the actor, rechecked at save time. A Claude level never grants more than these:

| Tool | Check |
|---|---|
| find/read entries | `view` (unlisted otherwise) |
| edit in place | `edit` |
| translate | `view` on the source, `edit` on the localization; a new one is checked on the unsaved `makeLocalization()`, so access to the target site counts |
| duplicate, compose new | `create` with `[Entry::class, $collection, $site]` |
| publish / unpublish | `publish` |
| list assets | `view` on the asset container (`view {container} assets`) |
| image upload | `store` on the asset container (`upload {container} assets`), when the link is made and again on upload |
| glossary, style rules | `GlossaryAccess` (`edit translation glossary` permission; super admins always) |

## Writes: preview, then confirm

Each write is two calls. The preview returns a `confirmation_token` (cached 30 minutes, bound to the actor's user id, carrying a fingerprint of the entry); the save needs that token plus `confirmed: true` and refuses with `entry_changed` if the fingerprint moved. Keep this shape for any new write tool.

Edits, publishing and composing use a normal `save()` plus `updateLastModified($actor)`, so host listeners (sitemaps, git automation) run as for a Control Panel save. Translations save quietly and queue the commit through `ContentCommitter`.

## Editing in place

`EntryTranslator::editableSegments()` / `applySegmentEdits()` reuse the translation walker in `Concerns/TranslatesFields`:

- Text segments have numeric ids. Value segments (`toggle`, `select`, `button_group`, `checkboxes`, `radio`, `link`, `assets`) have ids `v{n}`, carry label, options and a location such as `Komponenten › tiles 2 › Kacheln › row 1 "Bankkaution"` (the block's own title, heading or label, so Claude can match the section a user names), and are collected only while `collectingValues` is on; translation never sees them.
- Assets segments have kind `asset`, not `image`: an assets field may hold a video or a file. They carry `container`, `folder`, `restrict_to_folder` and `multiple`; a value must exist on the container's disk (the disk, not the asset listing cache, which misses files written outside Statamic) and is stored as the field stores it: one path when `max_files` is 1, a list otherwise. A `container::` prefix is stripped.
- A value field absent from the data gets the `MISSING_VALUE` placeholder and is dropped again unless an edit sets it, so untouched entries gain no keys.
- An unedited text segment returns its original string; an unedited merged Bard block returns its `__original__` nodes instead of an HTML round trip. Untouched content stays byte-identical, which the tests assert.

## Images

Claude cannot pass a chat attachment to a tool (it sees the picture, not its bytes), so `ImageUploads` hands out an upload link instead:

- `prepare_image_upload` caches a record under a random 40-character token (30 minutes, bound to the actor, container, sanitized folder, optional file name and Claude's alt text). The token in `/mcp/upload/{token}` is the only credential: the page and its POST need no session or bearer token, so the user can open it from the chat and Claude's sandbox can `curl -F file=@…` it. CSRF is off for that POST.
- The POST validates like the Control Panel (`AllowedFile`, the container's validation rules) plus images only and `image_fetch.max_bytes`, sets the alt text before `upload()` so the first save carries it, and runs as the link's account so asset events see it. A link takes one image; a second post gets 409. The path returned is `$asset->path()` after upload, because Statamic's uploader may lowercase the name or change the extension (source preset).
- Uploading changes no page, so it has no preview/confirm of its own; placing the image is a normal entry edit with one. A name that exists gets `-2`, `-3`; the uploader adds a timestamp as a last resort, so nothing is ever overwritten.
- `list_assets` lists one folder (files plus subfolders), or with a query searches file names below it; at most 100 files. URLs are returned once as `url_prefix` (container URL + path) instead of per file, which saves about a quarter of the result on an S3 container.
- In a host app whose `assets` disk is S3, a test upload lands in the real bucket: delete the asset again through Statamic after verifying.

## Composing and refactoring

`get_content_model` / `get_block_schemas` / `preview_compose_entry` / `save_compose_entry` reuse the AI agent through `EntryGeneratorService::compositionBrief()`, `blockSchemas()`, `mapComposedData()` and `mergeComposedData()`. The agent's own prompt and mapping stay unchanged; the connector path differs deliberately:

- **Two-step brief.** Step one is the block catalog (every block's `ai_description` and `when_to_use` from the host's `set-hints.yaml`), the entry's own fields, rules, site instructions, glossary and style rules, without block fields. `get_block_schemas` returns fields for the chosen blocks; a refactor's brief includes the blocks already on the page. Descriptions repeated across fields become numbered notes. This halves the tokens with no loss of guidance.
- **No agent fill-ins.** Empty links stay empty (no home-page fallback), a library image fills only a required image field (a random picture in an optional slot changes how a block renders), and missing required fields are reported by validation for Claude to write instead of getting placeholder text.
- **Image paths.** The agent's mapper skips asset fields; `carryAssetPaths()` keeps a path Claude names when `fieldHasAssetPath()` allows it.
- **Refactors keep stored blocks.** The agent's mapper does not round-trip stored blocks: it drops asset paths and entry references and re-enables disabled sets. `splitKeptBlocks()` / `reassembleBlocks()` therefore keep a block sent back with its stored `id` exactly as stored and map only the keys Claude changed; blocks without an id are new and mapped whole.
- **Validation** runs on `preProcess()`ed values, as the Control Panel does. A dated collection keeps its date on the entry (filename), not in the data, so the date is added for validation and set with `$entry->date()` on save.
- **Placement.** New entries are saved unpublished and appended to the structure (under `parent_entry_id` when given); a copy goes next to its original, as `DuplicateEntry` places it.

## Verifying against a host app

Unit tests use tiny blueprints. A real blueprint surfaces what they cannot: dated collections, anchor links, the agent's fill-ins, mapper loss. A connector change is done when it also works in a host app:

1. In the host app (with the addon symlinked or freshly updated), run tools through tinker: `app(TranslationMcpServer::class)`, `->bridge()->setActor($user)`, then `dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [...]])`. Use temporary users with a real role, not a super admin, so permissions are exercised.
2. Diff the content files: only the intended keys plus `updated_by`/`updated_at` may change.
3. Clean up: delete created entries and temporary users, `git checkout -- content/trees`, `php artisan statamic:stache:refresh`, and confirm `git status` is clean.

Host apps pin the addon in `composer.lock` (`dev-main` at a commit): ship with `composer update motion-work/statamic-ai-assistant` and commit the lock.

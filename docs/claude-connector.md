# Claude connector (MCP)

The site exposes `{APP_URL}/mcp` for the Claude app. Claude writes the text; the addon only reads Statamic, describes a change, and saves what Claude sends back after the user agrees. DeepL and Infomaniak are never called from here.

## Request flow and sign-in

- Routes live in `routes/web.php`: `/mcp`, `/.well-known/oauth-*`, `/mcp/oauth/{register,authorize,token}`, `/mcp/upload/{token}`.
- `McpOAuth`: OAuth with PKCE and dynamic client registration; clients, codes and tokens live in the cache (access 12 h, refresh 30 days). Any Control Panel account signs in; there is no email-domain gate and no shared token.
- Registration is open (MCP clients self-register), so the redirect host is the trust boundary: `redirectIsAllowed()` accepts only `mcp_redirect_hosts` (plus http `localhost`), at registration and again in `redirectHost()` on every sign-in, so clients cached before a host was removed stop working. The sign-in page shows that host and hides the form for an invalid request. Public clients (`token_endpoint_auth_method: none`, what Claude registers) get no secret and rely on PKCE; confidential ones must present theirs. Client records without `auth_method` predate this and are public.
- OAuth records go to `mcp_cache_store` (`McpOAuth::store()`), not the default cache, when set. A host whose deploy runs `cache:clear` must point it at a store that survives it (`database`), or every deploy signs everyone out. Preview and upload tokens stay in the default cache; they live 30 minutes.
- The sign-in form carries a CSRF token and is throttled to 10 posts per minute. A script driving it fetches the form first (cookie jar + `_token`), and a 429 means wait a minute.
- `McpController` → `TranslationMcpServer::dispatch()` (JSON-RPC, tool list and schemas; `HANDLERS` maps each tool to a method and its arguments, which `McpArguments` reads) → `ClaudeTranslationBridge`, a facade over the tool families `LocalizationTools`, `EntryEditTools`, `EntryLifecycleTools`, `AssetTools`, `ComposeBrief`, `ComposeTools`, `ComposeSaver` and `GlossaryTools`. They share one `ConnectorContext`: the actor, entry lookup, the policy refusals, fingerprints and slugs. `KeptBlocks`, `EntryOutline` and `SegmentValueCheck` are stateless helpers. A new tool goes into the family it belongs to and into `HANDLERS`.
- `ClaudeTranslationBridge::setActor()` (stored on `ConnectorContext`) also calls `auth()->setUser()`, so Statamic policies, `updated_by`, git commit authors and Statamic's own actions (`DuplicateEntry`) see the signed-in account. `bin/mcp` (stdio) has no actor: it reads and translates, and every write that needs an account is refused.
- Tool results are compact JSON; only the model reads them.

## Permissions

Two layers. First, a super admin may give each role or user a Claude level on the **AI & Claude access** page (`Support/ClaudeAccess`): read ⊂ edit ⊂ publish. A role has one level (it sits in one `claude_<level>.roles` list); a user gets the highest level of their roles unless they are in a `claude_<level>.users` list, which wins even when lower (`claude_none` is a user-only “no access”). `TranslationMcpServer::TOOL_LEVELS` names the level each tool needs (unlisted tools need read; previews of a write need the write's level). `tools/list` hides what the account may not use and `tools/call` refuses it, so a grant revoked mid-session takes effect on the next call; sign-in needs read, and the upload link checks edit again on upload. The levels only apply while `claude_restricted` is on in `access.yaml`; it is off by default so an update locks nobody out. Super admins and the stdio server are never restricted. When adding a write tool, add it to `TOOL_LEVELS`.

Second, every tool follows Statamic's own policies for the actor, rechecked at save time. A Claude level never grants more than these:

| Tool | Check |
|---|---|
| find/read entries | `view` (unlisted otherwise) |
| edit in place | `edit` |
| translate | `view` on the source, `edit` on the localization; a new one is checked on the unsaved `makeLocalization()`, so access to the target site counts |
| duplicate, compose new | `create` with `[Entry::class, $collection, $site]` |
| publish / unpublish | `publish` |
| list assets, review_images | `view` on the asset container (`view {container} assets`) |
| preview/save asset alt text | `store` or `edit` on the asset (`upload {container} assets` is enough in most roles) |
| image upload | `store` on the asset container (`upload {container} assets`), when the link is made and again on upload |
| glossary, style rules | `GlossaryAccess` (`edit translation glossary` permission; super admins always) |

## Writes: preview, then confirm

Each write is two calls. The preview returns a `confirmation_token` (cached 30 minutes, bound to the actor's user id, carrying a fingerprint of the entry); the save needs that token plus `confirmed: true` and refuses with `entry_changed` if the fingerprint moved. Keep this shape for any new write tool.

Edits, publishing and composing use a normal `save()` plus `updateLastModified($actor)`, so host listeners (sitemaps, git automation) run as for a Control Panel save. Translations save quietly and queue the commit through `ContentCommitter`.

## Editing in place

`EntryTranslator::$editSegments` (`EntryEditSegments::editableSegments()` / `applySegmentEdits()`) reuses the translation walker in `FieldTextCollector`:

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
- **Alt text on existing images:** `review_images` returns a batch (six at a time) with inline JPEG previews, the asset blueprint's locale→handle map (`alt`, `alt_fr`, …), current meta, and a copy brief. Claude writes the strings; the CMS never calls Infomaniak or DeepL. `preview_asset_alts` / `save_asset_alts` follow the usual confirmation token (edit level, plus store or edit on the container). Scope `missing` (default) skips assets that already have every requested handle filled.
- In a host app whose `assets` disk is S3, a test upload lands in the real bucket: delete the asset again through Statamic after verifying.

## Composing and refactoring

`get_content_model` / `get_block_schemas` / `preview_compose_entry` / `save_compose_entry` reuse the AI agent through `EntryComposer` (`compositionBrief()`, `blockSchemas()`, `mapComposedData()`, reached as `EntryGeneratorService::$composer` so it shares the generator's schema, prompt and mapper) and `EntryWriter::merge()`. The agent's own prompt and mapping stay unchanged; the connector path differs deliberately:

- **Two-step brief.** Step one is the block catalog (every block's `ai_description` and `when_to_use` from the host's `set-hints.yaml`), the entry's own fields, rules, site instructions, glossary and style rules, without block fields. `get_block_schemas` returns fields for the chosen blocks; a refactor's brief includes the blocks already on the page. Descriptions repeated across fields become numbered notes. This halves the tokens with no loss of guidance.
- **No agent fill-ins.** Empty links stay empty (no home-page fallback), a library image fills only a required image field (a random picture in an optional slot changes how a block renders), and missing required fields are reported by validation for Claude to write instead of getting placeholder text.
- **Image paths.** The agent's mapper skips asset fields; `EntryComposer::carryAssetPaths()` keeps a path Claude names when `fieldHasAssetPath()` allows it.
- **Refactors keep stored blocks.** The agent's mapper does not round-trip stored blocks: it drops asset paths and entry references and re-enables disabled sets. `Mcp/KeptBlocks` (`splitKeptBlocks()` / `reassembleBlocks()`) therefore keeps a block sent back with its stored `id` exactly as stored and map only the keys Claude changed; blocks without an id are new and mapped whole.
- **Validation** runs on `preProcess()`ed values, as the Control Panel does. A dated collection keeps its date on the entry (filename), not in the data, so the date is added for validation and set with `$entry->date()` on save.
- **Placement.** New entries are saved unpublished and appended to the structure (under `parent_entry_id` when given); a copy goes next to its original, as `DuplicateEntry` places it.

## Verifying against a host app

Unit tests use tiny blueprints. A real blueprint surfaces what they cannot: dated collections, anchor links, the agent's fill-ins, mapper loss. A connector change is done when it also works in a host app:

1. In the host app (with the addon symlinked or freshly updated), run tools through tinker: `app(TranslationMcpServer::class)`, `->bridge()->setActor($user)`, then `dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [...]])`. Use temporary users with a real role, not a super admin, so permissions are exercised.
2. Diff the content files: only the intended keys plus `updated_by`/`updated_at` may change.
3. Clean up: delete created entries and temporary users, `git checkout -- content/trees`, `php artisan statamic:stache:refresh`, and confirm `git status` is clean.

Host apps pin the addon in `composer.lock` (`dev-main` at a commit): ship with `composer update motion-work/statamic-ai-assistant` and commit the lock.

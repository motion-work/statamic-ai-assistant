# Service accounts (control plane → `/mcp`)

A control plane that provisions and fills a site without a person in the chat (mateo, motion-work/mateo#9) calls the same `/mcp` endpoint the Claude app uses, with a long-lived bearer token instead of the OAuth sign-in. This document is the contract its MCP client relies on.

## Turning it on and minting a token

```bash
# .env of the Statamic site
STATAMIC_AI_ASSISTANT_MCP_SERVICE_TOKENS=true
# optional: where the token hashes live (default storage/app/statamic-ai-assistant/service-tokens.yaml)
STATAMIC_AI_ASSISTANT_MCP_SERVICE_TOKENS_PATH=

php artisan statamic-ai-assistant:service-token mateo@instance.local --name=mateo --plain
```

- The email must be an existing Control Panel user. `--plain` prints only the token and a newline (for scripts); without it the command prints a short explanation around it and warns when the flag is off.
- Tokens look like `sai_svc_` + 48 letters and digits. Only `sha256(token)` is stored, with the token name, user id and creation time; the plaintext is shown once.
- Minting the same `--name` for the same user again replaces (rotates) the token. `--revoke` removes it. Deleting the user also invalidates it.
- With the flag off every service token is refused (HTTP 401) and `/mcp` behaves exactly as before. Run `php artisan config:clear` after changing `.env` on a cached config.

## What the token may do

The token acts as its user, exactly like an OAuth sign-in of that user: Statamic policies, the Claude level on **AI & Claude access** (`TOOL_LEVELS`, only while `claude_restricted` is on), `updated_by` and git commit authors. On top of the normal tools it is offered three service-only tools. Every tool and the user rights it needs:

| Tool | Claude level | User rights (super admins always pass) |
|---|---|---|
| `list_blocks` | read | none beyond a level; also offered to Claude app users |
| `write_site_instructions` | edit | the `agent_settings` grant on **AI & Claude access** (`access.yaml`: `agent_settings: { roles: [mateo] }`), as for the AI instructions page |
| `set_style_rules` | edit | the role permission `edit translation glossary` |
| `install_package` | edit | the role permissions `configure collections` and `configure fields` |

So the instance template's `mateo` role needs: `access cp`, the site and collection permissions for the entries it creates, `edit translation glossary`, `configure collections`, `configure fields`, an `agent_settings` grant for the role in `content/statamic-ai-assistant/access.yaml`, and, if the site restricts Claude, `claude_edit: { roles: [mateo] }`.

### Why the service-only tools skip preview/confirm

Every other write in the connector is a preview plus a confirmed save, because a person in the Claude app must see the change first. A control plane has nobody to show a preview to; a two-step dance would only add a round trip it confirms blindly. These three tools therefore save at once, and to keep the Claude app's contract intact they are hidden from `tools/list` and refused (`service_account_only`) for anything but a service token. They replace whole site-wide documents (idempotent: calling again with the same input changes nothing), the YAML they write is versioned under `content/` like the Control Panel's, and git automation commits each change as the service user.

## HTTP

```
POST {APP_URL}/mcp
Authorization: Bearer sai_svc_…
Content-Type: application/json
Accept: application/json
```

- Body: one JSON-RPC 2.0 request object (no batches). No `initialize` handshake is required; `tools/call` works on the first request. A request without `id` is a notification and gets `202` with an empty body.
- `200` with a JSON-RPC response for every request with an `id`, including tool errors.
- `401` `{"error":"unauthorized","message":"Sign in with a Control Panel account."}` (plus a `WWW-Authenticate: Bearer resource_metadata=…` header) when the token is missing, unknown, revoked, its user deleted, or service tokens are off.
- `400` `{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}` when the body is not a JSON-RPC 2.0 object.
- `429` after 120 requests per minute per client (Laravel `throttle:120,1`).
- JSON-RPC errors: `-32601` unknown method, `-32603` an exception inside a tool (`error.message` holds it).

### Tool results

`tools/call` always answers in MCP's shape. The tool's own result is a JSON object serialised into `result.content[0].text`; decode it. `result.isError` is `true` exactly when that object has an `error` key.

```json
{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"{…tool result JSON…}"}],"isError":false}}
```

Errors every tool can return inside the text: `{"error":"forbidden","message":"…"}` (Claude level or role too low; nothing changed), `{"error":"service_account_only","message":"…"}` (called without a service token), `{"error":"unknown_tool","message":"…"}`.

Listing the tools a token may use: `{"jsonrpc":"2.0","id":1,"method":"tools/list"}` → `result.tools[]` of `{name, description, inputSchema}`.

## The five tools

### `list_blocks`

Every replicator/components set ("block") across the visible collection entry blueprints, with the editor-written hint from the AI instructions page. Both arguments are optional filters.

```json
{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_blocks","arguments":{}}}
{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"list_blocks","arguments":{"collection":"pages","blueprint":"page"}}}
```

Result (decoded `content[0].text`), blocks sorted by handle, one row per handle however many blueprints use it:

```json
{
  "blocks": [
    {
      "handle": "hero",
      "title": "Hero",
      "ai_description": "Grosser Einstieg mit Bild.",
      "when_to_use": ["Erste Sektion jeder Seite"],
      "has_hint": true,
      "content_mix": "short text, images / visual",
      "used_in": [{"collection": "pages", "blueprint": "page", "field": "blocks"}]
    },
    {"handle": "text", "title": "Text", "ai_description": "", "when_to_use": [], "has_hint": false, "content_mix": "rich text", "used_in": [ … ]}
  ],
  "count": 2,
  "without_hints": ["text"]
}
```

`used_in` holds handles. Nested sets (a set inside a set or a grid) are listed too; `field` is then the inner replicator's handle. An unknown collection: `{"error":"missing_collection","message":"…"}`. A good smoke test after provisioning: `count > 0`.

### `write_site_instructions`

Replaces the site instructions (Markdown) shown on the **AI instructions** page (`/cp/ai-block-hints`) and stored as `site_instructions` in `set-hints.yaml`. The AI agent's planner and generator prompts, the field AI buttons and the connector's `get_content_model` brief all carry them (the agent's prompts and the brief take the first 4000 characters). An empty string removes them.

```json
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"write_site_instructions","arguments":{"markdown":"# Showroom Muster\n\nImmer per Du. Termine statt Shop."}}}
```

```json
{"saved": true, "characters": 52, "previous_characters": 0, "prompt_characters": 4000, "truncated_in_prompts": false}
```

At most 8000 characters (as on the CP page), counted after trimming: `{"saved":false,"error":"too_long","message":"…"}`. Without the `agent_settings` grant: `{"saved":false,"error":"forbidden",…}`. Keep it under 4000 characters for prompts to carry all of it; `truncated_in_prompts` says when they do not.

While service tokens are on, the AI instructions page is shown even when the site has no AI provider key (normally it needs `OPENROUTER_API_KEY` or `INFOMANIAK_API_TOKEN`), so the brief is visible there after provisioning.

### `set_style_rules`

Replaces the style rules (tone, banned words) of the **Glossary & style rules** page. They go into the AI agent's prompt and the `get_content_model` brief (`STYLE & TONE RULES`, for the language being written) and into translations. Two forms:

```json
{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"set_style_rules","arguments":{"rules":["Nie \"günstig\" schreiben.","Immer per Du."]}}}
{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"set_style_rules","arguments":{"rules":{"de":["Immer per Du."],"fr":["Tutoyer."]}}}}
```

- A list applies to every site language (the base language of each site's locale: `de_CH` → `de`).
- An object maps language codes to lists; languages left out lose their rules. A string instead of a list is one rule. Blank strings are dropped.

```json
{"saved": true, "rules": {"de": ["Nie \"günstig\" schreiben.", "Immer per Du."], "fr": ["Nie \"günstig\" schreiben.", "Immer per Du."]}, "synced_to_deepl": false}
```

The rules are not pushed to DeepL (saving them on the CP page does that when a DeepL key is set). Without `edit translation glossary`: `{"saved":false,"error":"forbidden",…}`.

### `install_package`

Runs the installer of a content package that is already required with Composer, which publishes its blueprints, collections and forms.

```json
{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"install_package","arguments":{"key":"showroom-retail"}}}
```

```json
{"installed": true, "key": "showroom-retail", "via": "artisan:mateo-package:install", "output": "…command output, at most 4000 characters…"}
```

- The installer is the callable the package registered for that key, `app(\MotionWork\StatamicAiAssistant\Support\PackageInstallers::class)->register('showroom-retail', fn (string $key) => 'report')` (`via: "registered"`), else the `mateo-package:install` artisan command when an installed package provides it, called with the key as its first argument (`via: "artisan:mateo-package:install"`).
- Errors: `invalid_key` (key must match `^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$`), `forbidden` (missing `configure collections` / `configure fields`; the message names them), `no_installer`, `install_failed` (the installer threw or the command exited non-zero; `message` carries its output). All with `"installed": false`.
- The Stache is not refreshed by this tool; the installer is responsible for that, and the next HTTP request sees the new files anyway.

### `get_content_model` and the rest

All other connector tools (`find_entries`, `get_content_model`, `get_block_schemas`, `preview_compose_entry` / `save_compose_entry`, `set_published`, …) work for a service token as described in `docs/claude-connector.md`, including their preview/confirm step: the control plane calls the preview, then the save with `"confirmed": true` and the `confirmation_token`.

## Config

| Key | Env | Default |
|---|---|---|
| `mcp_service_tokens` | `STATAMIC_AI_ASSISTANT_MCP_SERVICE_TOKENS` | `false` |
| `mcp_service_tokens_path` | `STATAMIC_AI_ASSISTANT_MCP_SERVICE_TOKENS_PATH` | `storage/app/statamic-ai-assistant/service-tokens.yaml` |
| `mateo_control_plane_url` | `STATAMIC_AI_ASSISTANT_MATEO_CONTROL_PLANE_URL` | `null` (optional, not read yet) |

The token file sits outside `content/` so git automation does not commit it; a host whose `storage/` does not survive deploys points the path at a persistent volume, or mints a new token after each deploy.

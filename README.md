# Statamic AI Assistant

> Control Panel addon for **Statamic 6.5+**: AI text generation and refactoring via custom fieldtypes and Bard, plus optional DeepL translation tools.

Maintained by [Motion Work](https://github.com/motion-work).

## Requirements

- **Statamic `^6.5`** (`statamic/cms` is declared in Composer; installs on Statamic 5 will not resolve).
- A compatible **Laravel** version for your Statamic release (follow [Statamic’s requirements](https://statamic.dev/installation#requirements)).
- **Current release line:** **`3.x`** (see [CHANGELOG.md](./CHANGELOG.md) for breaking changes from 2.x).

## Supported API

- [OpenRouter](https://openrouter.ai) (one API for every model; the addon picks the model per task). Used automatically once `OPENROUTER_API_KEY` is set.
- Infomaniak (CH, respect the data of your clients). Used when no OpenRouter key is set.


## Features

- Allow the usage of 'Ai Textarea' custom component in Statamic
- Allow the usage of 'Ai Text' custom component in Statamic
- Upgrade Bard by adding the AI asisstant buttons (generation and translation) directly in Bard
- Multilang supported (CP panel addon translations in EN/DE/FR/IT for now)

## How to Install

Install with Composer. Current releases target **Statamic 6.5+** on the **`3.x`** line:

```bash
composer require motion-work/statamic-ai-assistant:^3.0
```

## Claude app

Translators connect the Claude app (claude.ai, Claude Desktop, or the mobile app) to the site. Claude translates with their own Claude account. The site does not call DeepL or Infomaniak for this. It saves the translation as a localization of the original entry, and on a site with Statamic git automation it commits that save the same way the Control Panel does.

The connector URL is `{APP_URL}/mcp`. `APP_URL` must be the public HTTPS address of that install, because Claude's servers call it. The same package path works on every Statamic site that installs it.

In Claude, open **Customize → Connectors → Add custom connector**:

1. URL: `https://your-site.example/mcp`
2. Authentication: **Sign in when needed**
3. OAuth client: **Register automatically**

Each translator then signs in with a Control Panel account. The email domain does not matter. An address that is not a Control Panel account cannot connect. Claude shows a preview and writes only after they agree. For French, they ask Claude to localize onto the `fr` site.

Only allowed apps can connect: the sign-in hands its code only to the callback hosts in `STATAMIC_AI_ASSISTANT_MCP_REDIRECT_HOSTS` (comma-separated, default `claude.ai,claude.com`; `localhost` always works). This stops a stranger from registering a client that sends the sign-in to their own server. The sign-in page names the host that gets access. The connector is a standard MCP server, so other MCP clients can use it too: to connect ChatGPT, add `chatgpt.com` to the list.

Connected apps and their sign-ins are kept in the cache. If your deploy runs `php artisan cache:clear`, set `STATAMIC_AI_ASSISTANT_MCP_CACHE_STORE` to a store it does not clear, for example `database` (create the table with `php artisan make:cache-table` and migrate). Otherwise everyone has to sign in to the connector again after each deploy.

Claude can also change the text of an existing entry in place, in any language ("passe den Einleitungstext der Bankkaution-Seite an"). That follows the account's Control Panel role, as for editing in the Control Panel: the collection's edit permission, access to the entry's site, and edit other authors' entries where the blueprint has an author. Claude shows the before and after of each changed text and saves only after the user agrees. Text it was not asked to change stays exactly as it was, and so does the published state. Claude can also change toggles, choice fields (select, button group, radio, checkboxes) and links, including inside blocks: a choice must be one of the field's options, and a link a URL, `mailto:`, `tel:`, a `/path`, a `#anchor` or another entry. It can also change the slug; it is formatted like the Control Panel slug field, must be unique in the collection and site, and the preview warns that the page's web address changes. The save fires Statamic's normal save events, so git automation and other listeners run as for a Control Panel save.

Claude can also duplicate an entry and publish or unpublish one, again only where the role allows it: duplicating needs the collection's create permission on that site and works like the Control Panel's Duplicate action (an unpublished copy with "(Duplicated)" and a new slug, plus its localizations). Publishing needs the publish permission and changes one language at a time. Claude describes each change first and acts only after the user agrees.

Claude can also build a whole entry from a goal ("erstelle eine Landingpage für Studierende mit diesen Infos …") or restructure an existing one. It first reads the same brief the AI agent uses: every content block with the descriptions and "when to use" rules from the **AI block hints** page, the site instructions, glossary and style rules. It then shows an outline and saves only after the user agrees. New entries are saved unpublished in the page tree; a restructured entry is saved in place or as an unpublished copy, whichever the user picks. Blocks it keeps stay exactly as they were. The better the block hints, the better Claude chooses blocks, so fill them in for every block. How blocks combine into a page (typical order per page type, anchors, what never to invent) belongs in the site instructions on the AI instructions page; Claude and the AI agent both read them.

Claude can also put images on a page ("ersetze das Bild im Hero mit dem im Anhang", "füge dieses Bild in der Section Bankkaution ein"). The Claude app cannot hand a chat attachment to the connector, so Claude gives the user a one-time upload link (valid 30 minutes, one image, up to `image_fetch.max_bytes`, 10 MB by default): they open it, drop the image, and tell Claude it is done. Claude uploads the attachment itself, with no link to click, when code execution is on in the Claude app and the site's domain is listed under Settings → Capabilities → Code execution and file creation → Additional allowed domains (on Team/Enterprise plans an admin may have to allow it). The image goes into the asset container and folder Claude names (the image field's own folder unless the user asks for another), with an alt text Claude writes from the picture; an existing file is never overwritten. Uploading needs the role's “Upload assets” permission for that container. Claude then sets the image field like any other value, with a before/after preview and a save after the user agrees. Image fields (and other assets fields, such as a video file) are listed at the top of the entry and inside blocks, named by the block's heading, including empty ones, and only take assets that exist in the field's container (and folder, when the field is restricted to it).

Claude can also list the asset library ("welche Bilder sind im Ordner illustrationen?"): the files and subfolders of a folder with their alt texts, or the files whose name contains a search term. That needs the role's “View assets” permission for the container, and lets Claude set an image that is already in the library.

A super admin can also limit what each person may do through Claude, separately from the Control Panel, at the top of **AI & Claude access**: choose “Set per role and user” and give each role a level, *No access*, *Read*, *Edit* or *Publish* (each includes the ones before it). A user gets the highest level of their roles; a level set for the user personally replaces it, also when it is lower. For example, the Editor role has *Edit*, and Michi personally has *Read*: he edits in the Control Panel but only reads through Claude. Accounts without a level cannot sign in to the connector. The Control Panel role still applies on top, so Claude never does more than the account may in the Control Panel. With “Same as the Control Panel” (the default after updating), everyone keeps the access their role gives them.

Translating follows the role the same way. Claude only lists and reads entries the account may view, and only saves a localization the account may edit, which includes access to the target site. The glossary and style rules can be read by everyone, and the **Glossary & style rules** page is shown even without a DeepL key or an AI token, since Claude follows them. Changing them, in Claude and on the Control Panel page, needs the role permission “Edit translation glossary and style rules” (Users → Roles, group “AI Assistant”); super admins always may.

A developer can also run the same tools locally, without a public URL, from the Statamic site:

```bash
php vendor/motion-work/statamic-ai-assistant/bin/mcp
```

The local server has no signed-in account and no role checks, so it can translate but not edit entries in place.

## How to Use
The provider is picked automatically (`STATAMIC_AI_ASSISTANT_PROVIDER_NAME=auto`, the default): **OpenRouter** once `OPENROUTER_API_KEY` is set, otherwise **Infomaniak**, the Swiss solution🇨🇭. Set `STATAMIC_AI_ASSISTANT_PROVIDER_NAME` to `openrouter` or `infomaniak` to choose one explicitly. Without the active provider's key, the AI features are hidden in the Control Panel.

### OpenRouter

[OpenRouter](https://openrouter.ai) is one API in front of every major model. The addon decides which model each task gets, falls back to the next model when one is down, and reports what every call cost.

**Setup**

1. In the OpenRouter dashboard, create **one API key per client site** and give it a **spending limit** (Settings → API keys). A key per site keeps spend capped, attributable on the AI usage page, and revocable on its own. Do not put a management key on a site.
2. Set it in the site's `.env`:
   ```env
   OPENROUTER_API_KEY=sk-or-v1-...
   ```
3. Optional: `OPENROUTER_APP_TITLE` (shown in your OpenRouter activity; defaults to `APP_NAME`). The site's `APP_URL` is sent as the referer, so each site shows up separately.

**Models per task (profiles)**

Every AI call runs as one of five tasks. Each has an ordered model list: the first model answers, the rest are fallbacks OpenRouter tries when it is unavailable. Override a list with a comma-separated env value:

| Task | Used by | Env | Default reasoning |
|---|---|---|---|
| agent | AI agent planning and entry generation | `OPENROUTER_AGENT_MODELS` | medium |
| light | quick helpers (target selection, block hints) | `OPENROUTER_LIGHT_MODELS` | none |
| field | Ai Text / Bard generate and refactor | `OPENROUTER_FIELD_MODELS` | none |
| translate | translations without DeepL (LLM fallback) | `OPENROUTER_TRANSLATE_MODELS` | none |
| vision | alt text and image analysis | `OPENROUTER_VISION_MODELS` | none |
| image | image generation (see below) | `OPENROUTER_IMAGE_MODELS` | — |

The shipped defaults are in `config/statamic-ai-assistant.php` (`openrouter_profiles`). Each task also takes `OPENROUTER_<TASK>_REASONING` (`none`, `minimal`, `low`, `medium`, `high`) and `OPENROUTER_<TASK>_PRESET`.

**Presets.** Point a task at an [OpenRouter preset](https://openrouter.ai/docs/guides/features/presets) (`OPENROUTER_AGENT_PRESET=statamic-agent`) to manage its model and settings in the OpenRouter dashboard: a change there reaches every site using that key's organisation, with no release or config republish. With a model list set as well, the list wins and the preset adds its other settings; leave the list empty (`OPENROUTER_AGENT_MODELS=`) to let the preset pick the model.

**Privacy and data residency**

- By default the addon only routes to providers that do not collect prompts (`OPENROUTER_DATA_COLLECTION=deny`). `OPENROUTER_ZDR=true` restricts routing to zero-data-retention endpoints (fewer models qualify).
- `OPENROUTER_PROVIDER_ONLY` / `OPENROUTER_PROVIDER_IGNORE` (comma-separated provider slugs) limit which providers serve requests; `OPENROUTER_PROVIDER_SORT` (`price`, `throughput`, `latency`) changes how they are ranked.
- Requests are processed wherever the chosen provider runs. EU in-region routing needs an OpenRouter **Business or Enterprise** plan: set `OPENROUTER_BASE_URL=https://eu.openrouter.ai/api/v1`. Billing and account records stay in the US either way. **For Swiss hosting, use Infomaniak.**

**Structured outputs and prompt caching (AI agent)**

- `OPENROUTER_STRUCTURED_OUTPUTS=true` (default): entry generation sends a JSON Schema built from the blueprint, so models return valid entry JSON far more often. If a model rejects the schema, the call is repeated once without it; very deeply nested blueprints skip the schema. These requests send no `temperature`, because OpenRouter then only routes to providers that support every parameter, and most do not accept temperature together with a schema.
- `OPENROUTER_PROMPT_CACHING=true` (default): the agent keeps its large system prompt (instructions and blueprint schema) at the start and sends the agent session id, so repeated turns hit the provider's cache. Cached input costs roughly 0.1× (Anthropic) to 0.5× (OpenAI) of the normal price; Anthropic's first cache write costs 1.25×.

**Web search (AI agent)**

Editors switch web search on per message with the **Web search** button in the agent's composer. It is off by default and stays on for the follow-ups of that chat. The agent then looks things up on the web when the request needs current or external facts, lists its sources under the reply, and may open the pages it cited. Images are still only saved from pages the editor provided, never from search results.

- The search runs inside OpenRouter (`openrouter:web_search`), so the button only appears with the OpenRouter provider, and not on a regional host (the EU host has no search engine).
- Search queries go to the search provider (Exa, Parallel, Perplexity or the model's own search). OpenRouter's zero-data-retention setting does not cover them.
- Each search costs about $0.007–0.015 on top of the tokens; the AI usage page counts them.
- Settings: `STATAMIC_AI_ASSISTANT_WEB_SEARCH` (default `true`; `false` removes the button), `_WEB_SEARCH_ENGINE` (`auto`), `_WEB_SEARCH_MAX_RESULTS` (`5` per search), `_WEB_SEARCH_MAX_TOTAL_RESULTS` (`15` per request), `_WEB_SEARCH_ALLOWED_DOMAINS` / `_WEB_SEARCH_EXCLUDED_DOMAINS` (comma-separated).

**Image generation (AI agent)**

The agent can create new images with an image model, when an editor explicitly asks for one ("Erstelle ein Hero-Bild einer Werkstatt"). It never generates images on its own to fill empty image fields; those are still filled from existing assets.

- **Off until a super admin switches it on:** AI assistant → **Who has access** → **Image generation**. `STATAMIC_AI_ASSISTANT_IMAGE_GENERATION=true` or `false` overrides that switch for the site (the page then shows it locked). Only with the OpenRouter provider.
- **Where images go:** each image is saved as an asset in the `ai-generated` folder of the entry's image container (`STATAMIC_AI_ASSISTANT_IMAGE_GENERATION_FOLDER`) and set on the entry's image field. It is saved when generated, before the entry: images of a discarded preview stay in that folder, so that is the place to clean up.
- **Labelling:** generated assets carry `ai_generated: true` and their prompt (`ai_prompt`) in their metadata, and get an alt text from the vision model.
- **Models and cost:** `OPENROUTER_IMAGE_MODELS`, tried in order. The defaults are FLUX 3 Klein (about $0.009 per 1K image) and Gemini 3.1 Flash Image as the fallback (about $0.07). The AI usage page lists image costs under "Image generation".
- **Limits and format:** at most `STATAMIC_AI_ASSISTANT_IMAGE_GENERATION_MAX_PER_ENTRY` images per entry (default `4`); `_ASPECT_RATIO` (`16:9`), `_RESOLUTION` (`1K`), `_FORMAT` (`jpeg`; FLUX does not accept `webp`).
- The agent is told never to depict a real, identifiable person, a brand logo or a copyrighted character.

**Semantic search (AI agent and Claude connector)**

Finds entries by meaning, not only by matching words: "update the page about returns" also finds "Garantie und Reparaturen". The AI agent uses it in `find_entries`, `search_entry_content` and when it looks up an entry to read; the Claude connector in `find_entries`. Results are merged with the keyword search, so an exact title or slug match always comes first. The @-mention picker and the matching of entry references keep the keyword search.

- **Build the index once:** `php artisan statamic-ai-assistant:embeddings build` (all sites; `--site=de` for one), or **Rebuild index** on the AI usage page (queued). After that, saving or deleting an entry updates its vector on the queue. `status` shows each site's index, `clear` deletes it. Re-running `build` only sends entries whose text changed.
- **Falls back to the keyword search** whenever semantic search cannot run: another provider than OpenRouter or no key, `STATAMIC_AI_ASSISTANT_SEMANTIC_SEARCH=false`, no index built yet, an index built with another model, more than `max_entries` (default 5,000) entries on a site, or a failed embedding request. Editors, the agent and Claude never see an error from it.
- **Storage:** one vector per entry localization, in `storage/app/statamic-ai-assistant/embeddings/<site>/` (no database). Change it with `STATAMIC_AI_ASSISTANT_SEMANTIC_SEARCH_PATH`.
- **Model and cost:** `OPENROUTER_EMBEDDING_MODEL` (default `openai/text-embedding-3-small`) at `OPENROUTER_EMBEDDING_DIMENSIONS` (default 512), about $0.02 per million tokens: roughly $0.03 to index 1,000 entries, a fraction of a cent per search. Changing either invalidates the index until it is rebuilt.
- **Privacy:** entry text and search queries are sent to the embedding provider through OpenRouter, with the provider settings above (`data_collection: deny` by default).

**AI usage page**

Super admins get **AI usage** in the AI assistant nav: cost, calls and tokens per month, broken down by task, model and person, the fallback rate, the latest calls, and the OpenRouter key's spending limit and what remains. Cost is only reported by OpenRouter (Infomaniak calls show tokens only). The log is stored as one JSON line per call in `storage/app/statamic-ai-assistant/usage/<YYYY-MM>.jsonl`. It holds the CP user id, task, model, tokens and cost, never prompts or replies. Settings: `STATAMIC_AI_ASSISTANT_USAGE_TRACKING` (default `true`), `STATAMIC_AI_ASSISTANT_USAGE_RETENTION_MONTHS` (default `12`), `STATAMIC_AI_ASSISTANT_USAGE_PATH`.

**When an OpenRouter limit is hit**, editors see what happened (out of credits, the site's spending limit reached, no model can serve the request) instead of a generic error, in the agent and on the field buttons.

**Model constraints to keep in mind when changing the defaults**

- Quick-task models (light, field, translate, vision) must accept reasoning effort `none`. Models with mandatory reasoning (for example Claude Sonnet or Gemini Flash thinking models) answer quick tasks with HTTP 400; keep them out of those lists or raise the task's `_REASONING`.
- Claude models with mandatory reasoning reject a forced tool call, which the agent uses on some turns. They are fine in the agent list, but put them last, where OpenRouter only falls back to them.
- Structured-output requests drop `temperature` (see above).

### Infomaniak API
1. Create an [Infomaniak](https://manager.infomaniak.com) account and generate an API token with access to your AI Tools. Define it in your `.env` file as ```INFOMANIAK_API_TOKEN``` 
2. Go to your dashboard computing->AI tools and define the `INFOMANIAK_PRODUCT_ID` in your `.env` file (or use their API to get the product id: https://developer.infomaniak.com/docs/api/get/1/ai)
   >tips for GET request: Just use postman with your api key in the Authorization header 'Bearer your-api-key' to get the product id

### Translations (DeepL)

> Translation features (Bard translate, the Translate entry action, bulk translation, terms and navigation) use **DeepL** when `DEEPL_API_KEY` is set. **Without a key they fall back to the configured AI model** (OpenRouter's translate task or Infomaniak), and the Control Panel tells editors each time. Glossary terms and style rules still apply. To require DeepL instead, set `STATAMIC_AI_ASSISTANT_TRANSLATION_LLM_FALLBACK=false`. To turn translations off, set `STATAMIC_AI_ASSISTANT_TRANSLATIONS=false`.

Bulk translation, Bard field translation, and related CP tools use **DeepL** (not the LLM provider above).

1. Create a [DeepL API](https://www.deepl.com/pro-api) account and copy your API authentication key.
2. Add it to your application `.env` file:

```env
DEEPL_API_KEY=your-deepl-api-key-here
```

3. After installing or updating the addon, publish config if you need to customize language mapping or English/Portuguese variants:

```bash
php artisan vendor:publish --tag=statamic-ai-assistant --force
```

The key is read from `config/deepl.php` (`DEEPL_API_KEY`). Optional overrides in `.env` (see `config/deepl.php` after publishing):

```env
# Optional: DeepL requires en-GB / en-US (not bare "en") for some targets
DEEPL_ENGLISH_TARGET=en-GB
DEEPL_PORTUGUESE_TARGET=pt-PT
```

You can disable translation features entirely with:

```env
STATAMIC_AI_ASSISTANT_TRANSLATIONS=false
```

#### Linked entries depth (admin-only)

When an entry references other entries (entries/link fields, Bard entry links, nested replicator data), the translator can **recursively** create or update localized siblings so IDs point to the correct language. **Editors do not choose this in the UI** — it is controlled by configuration.

- **Default:** `1` — one “hop”: linked entries directly used by the page are translated when needed; links inside those entries are not followed further.
- **Override:** set an integer from **0** to **5** in `.env`:

```env
# Optional. Default is 1. Use 0 to only remap IDs when a translation already exists.
STATAMIC_AI_ASSISTANT_LINKED_ENTRIES_MAX_DEPTH=1
```

Higher values increase DeepL usage and runtime (more entries may be created in one run). See `config/statamic-ai-assistant.php` (`linked_entries_max_depth`) for the full comment block.

### Customization in your .env file

- With OpenRouter, models are set per task (see **OpenRouter** above)
- You can override INFOMANIAK_MODEL with [the available model you want to use](https://www.infomaniak.com/fr/hebergement/ai-tools/open-source-models) / default is mistral24b
- You can override STATAMIC_AI_ASSISTANT_PREFACE for your custom needs.
  > default preface of the addon: 'You are a seasoned SEO expert who writes clear, professional, seo friendly and engaging articles in plain text. Your task is to produce fully readable articles for an end user. Do not include any markdown formatting, HTML tags, header tags, meta descriptions, or any other markup. Write only the final article text in plain text, ensuring it is clear, direct, and free of extraneous formatting elements.'
- you can override STATAMIC_AI_ASSISTANT_REFACTOR_PREFACE
  > default refactor preface of the addon: 'You are a professional text refactoring expert. Your only task is to transform the provided text according to the specific instructions given by the user. Output solely the final refactored text in plain text, DO NOT INCLUDE the user instructions, any commentary, or any additional text. Preserve the original language and maintain a similar length to the input text unless instructed otherwise.'
- you can override STATAMIC_AI_ASSISTANT_HTML_REFACTOR_PREFACE
  > default refactor preface of the addon: 'You are a professional text refactoring expert. Your only task is to transform the provided HTML text according to the specific instructions given by the user. Output solely the final refactored HTML! DO NOT INCLUDE the user instructions, any commentary, or any additional text. ALWAYS RENDER the output as valid HTML. Keep all links intact unless the user specifically instructs you to modify them, and always strive to preserve the original HTML structure unless instructed otherwise.ALWAYS update lorem ipsum. Preserve the original language and maintain a similar length to the input text unless otherwise specified.'
- You can override TEMPERATURE
- You can override MAX_TOKENS
- Reasoning ("thinking") models such as Kimi K2.6 or Qwen3.5 answer quick tasks (Ai Text / Bard generate and refactor, alt text, image analysis) without thinking first, which is much faster. Set `STATAMIC_AI_ASSISTANT_QUICK_TASKS_WITHOUT_THINKING=false` to keep thinking everywhere. The AI agent always keeps thinking on. Applies to OpenRouter (reasoning effort `none`) and Infomaniak.
- You can disable the AI agent for **editors only** with `ENABLE_AGENT_FOR_EDITORS=false` (super admins always keep access). The master switch `STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR=false` disables the agent for everyone.
- DeepL translation uses `DEEPL_API_KEY` in `.env`; without it, translations run on the AI model (see **Translations (DeepL)** above)

## Issues & ideas
If you run into problems or have ideas for improvements, please open an issue at [github.com/motion-work/statamic-ai-assistant/issues](https://github.com/motion-work/statamic-ai-assistant/issues). 

## License

MIT. See [LICENSE](./LICENSE).

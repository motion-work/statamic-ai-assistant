# Statamic AI Assistant

> Control Panel addon for **Statamic 6.5+**: AI text generation and refactoring via custom fieldtypes and Bard, plus optional DeepL translation tools.

Maintained by [Motion Work](https://github.com/motion-work).

## Requirements

- **Statamic `^6.5`** (`statamic/cms` is declared in Composer; installs on Statamic 5 will not resolve).
- A compatible **Laravel** version for your Statamic release (follow [Statamic’s requirements](https://statamic.dev/installation#requirements)).
- **Current release line:** **`3.x`** (see [CHANGELOG.md](./CHANGELOG.md) for breaking changes from 2.x).

## Supported API

- Infomaniak (CH, respect the data of your clients) - *Default*
- Groq (USA, get the speed)
- ...more to come


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

Claude can also change the text of an existing entry in place, in any language ("passe den Einleitungstext der Bankkaution-Seite an"). That follows the account's Control Panel role, as for editing in the Control Panel: the collection's edit permission, access to the entry's site, and edit other authors' entries where the blueprint has an author. Claude shows the before and after of each changed text and saves only after the user agrees. Text it was not asked to change stays exactly as it was, and so does the published state. Claude can also change toggles, choice fields (select, button group, radio, checkboxes) and links, including inside blocks: a choice must be one of the field's options, and a link a URL, `mailto:`, `tel:`, a `/path`, a `#anchor` or another entry. It can also change the slug; it is formatted like the Control Panel slug field, must be unique in the collection and site, and the preview warns that the page's web address changes. The save fires Statamic's normal save events, so git automation and other listeners run as for a Control Panel save.

Claude can also duplicate an entry and publish or unpublish one, again only where the role allows it: duplicating needs the collection's create permission on that site and works like the Control Panel's Duplicate action (an unpublished copy with "(Duplicated)" and a new slug, plus its localizations). Publishing needs the publish permission and changes one language at a time. Claude describes each change first and acts only after the user agrees.

Claude can also build a whole entry from a goal ("erstelle eine Landingpage für Studierende mit diesen Infos …") or restructure an existing one. It first reads the same brief the AI agent uses: every content block with the descriptions and "when to use" rules from the **AI block hints** page, the site instructions, glossary and style rules. It then shows an outline and saves only after the user agrees. New entries are saved unpublished in the page tree; a restructured entry is saved in place or as an unpublished copy, whichever the user picks. Blocks it keeps stay exactly as they were. The better the block hints, the better Claude chooses blocks, so fill them in for every block. How blocks combine into a page (typical order per page type, anchors, what never to invent) belongs in the site instructions on the AI instructions page; Claude and the AI agent both read them.

Translating follows the role the same way. Claude only lists and reads entries the account may view, and only saves a localization the account may edit, which includes access to the target site. The glossary and style rules can be read by everyone. Changing them, in Claude and on the Control Panel page, needs the role permission “Edit translation glossary and style rules” (Users → Roles, group “AI Assistant”); super admins always may.

A developer can also run the same tools locally, without a public URL, from the Statamic site:

```bash
php vendor/motion-work/statamic-ai-assistant/bin/mcp
```

The local server has no signed-in account and no role checks, so it can translate but not edit entries in place.

## How to Use
The plugin use per default Infomaniak API, a  Swiss solution🇨🇭 respecting the data of your users and the planet. However if you want to switch to a faster generation with a US based company this plugin also comes with Groq API support.

### Infomaniak API
1. Create an [Infomaniak](https://manager.infomaniak.com) account and generate an API token with access to your AI Tools. Define it in your `.env` file as ```INFOMANIAK_API_TOKEN``` 
2. Go to your dashboard computing->AI tools and define the `INFOMANIAK_PRODUCT_ID` in your `.env` file (or use their API to get the product id: https://developer.infomaniak.com/docs/api/get/1/ai)
   >tips for GET request: Just use postman with your api key in the Authorization header 'Bearer your-api-key' to get the product id

### Groq API
1. Create a [groq](https://groq.com/) account and generate an API key. Define it in your `.env` file as ```GROQ_API_KEY```
2. Add `STATAMIC_AI_ASSISTANT_PROVIDER_NAME='groq'` in your `.env` file to use groq

### Translations (DeepL)

> Translation features (Bard translate, the Translate entry action, bulk translation, terms and navigation) use **DeepL** when `DEEPL_API_KEY` is set. **Without a key they fall back to the configured AI model** (Infomaniak or Groq), and the Control Panel tells editors each time. Glossary terms and style rules still apply. To require DeepL instead, set `STATAMIC_AI_ASSISTANT_TRANSLATION_LLM_FALLBACK=false`. To turn translations off, set `STATAMIC_AI_ASSISTANT_TRANSLATIONS=false`.

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

- You can override GROQ_MODEL with [the available model you want to use](https://console.groq.com/docs/models) / default is llama-3.1-8b-instant
- You can override INFOMANIAK_MODEL with [the available model you want to use](https://www.infomaniak.com/fr/hebergement/ai-tools/open-source-models) / default is mistral24b
- You can override STATAMIC_AI_ASSISTANT_PREFACE for your custom needs.
  > default preface of the addon: 'You are a seasoned SEO expert who writes clear, professional, seo friendly and engaging articles in plain text. Your task is to produce fully readable articles for an end user. Do not include any markdown formatting, HTML tags, header tags, meta descriptions, or any other markup. Write only the final article text in plain text, ensuring it is clear, direct, and free of extraneous formatting elements.'
- you can override STATAMIC_AI_ASSISTANT_REFACTOR_PREFACE
  > default refactor preface of the addon: 'You are a professional text refactoring expert. Your only task is to transform the provided text according to the specific instructions given by the user. Output solely the final refactored text in plain text, DO NOT INCLUDE the user instructions, any commentary, or any additional text. Preserve the original language and maintain a similar length to the input text unless instructed otherwise.'
- you can override STATAMIC_AI_ASSISTANT_HTML_REFACTOR_PREFACE
  > default refactor preface of the addon: 'You are a professional text refactoring expert. Your only task is to transform the provided HTML text according to the specific instructions given by the user. Output solely the final refactored HTML! DO NOT INCLUDE the user instructions, any commentary, or any additional text. ALWAYS RENDER the output as valid HTML. Keep all links intact unless the user specifically instructs you to modify them, and always strive to preserve the original HTML structure unless instructed otherwise.ALWAYS update lorem ipsum. Preserve the original language and maintain a similar length to the input text unless otherwise specified.'
- You can override TEMPERATURE
- You can override MAX_TOKENS
- Reasoning ("thinking") models such as Kimi K2.6 or Qwen3.5 answer quick tasks (Ai Text / Bard generate and refactor, alt text, image analysis) without thinking first, which is much faster. Set `STATAMIC_AI_ASSISTANT_QUICK_TASKS_WITHOUT_THINKING=false` to keep thinking everywhere. The AI agent always keeps thinking on. Infomaniak only.
- You can disable the AI agent for **editors only** with `ENABLE_AGENT_FOR_EDITORS=false` (super admins always keep access). The master switch `STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR=false` disables the agent for everyone.
- DeepL translation uses `DEEPL_API_KEY` in `.env`; without it, translations run on the AI model (see **Translations (DeepL)** above)

## Issues & ideas
If you run into problems or have ideas for improvements, please open an issue at [github.com/motion-work/statamic-ai-assistant/issues](https://github.com/motion-work/statamic-ai-assistant/issues). 

## License

MIT. See [LICENSE](./LICENSE).

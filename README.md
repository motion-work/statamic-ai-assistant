# Statamic Ai Assistant

> Control Panel addon for **Statamic 6.5+**: AI text generation and refactoring via custom fieldtypes and Bard, plus optional DeepL translation tools.

## Requirements

- **Statamic `^6.5`** (`statamic/cms` is declared in Composer; installs on Statamic 5 will not resolve).
- A compatible **Laravel** version for your Statamic release (follow [Statamic’s requirements](https://statamic.dev/installation#requirements)).
- **Statamic 5:** use the **`1.x`** release line / branch, not current `main`.
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
composer require bold-web/statamic-ai-assistant:^3.0
```

```bash
# Statamic 5 projects only — pin the 1.x line
composer require bold-web/statamic-ai-assistant:^1
```

### Upgrading from 2.x → 3.0

1. `composer require bold-web/statamic-ai-assistant:^3.0`
2. `php artisan vendor:publish --tag=statamic-ai-assistant-config --force`
3. `php artisan vendor:publish --tag=statamic-ai-assistant --force`
4. Review [CHANGELOG.md](./CHANGELOG.md) — migration mode is removed; new DeepL glossary/style YAML; `ENABLE_AGENT_FOR_EDITORS` gates editors only (super admins always keep the BOLD agent).

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

> **Breaking change:** Translation features (Bard DeepL actions, bulk translation, and related Control Panel tools) **require a DeepL API key**. Add `DEEPL_API_KEY` to your `.env` as described below. If you upgrade from an older release, configure DeepL or turn translations off with `STATAMIC_AI_ASSISTANT_TRANSLATIONS=false`.

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
- You can disable the BOLD agent for **editors only** with `ENABLE_AGENT_FOR_EDITORS=false` (super admins always keep access). The master switch `STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR=false` disables the agent for everyone.
- DeepL translation requires `DEEPL_API_KEY` in `.env` (see **Translations (DeepL)** above)

## Upgrade Request
If you face issues or have improvements ideas do not hesitate to send us an email at [boldwebdev+statamic@gmail.com](mailto:boldwebdev+statamic@gmail.com). We’ll read all emails, but please understand that this package is maintained as a side project for us, so we may not get back to you directly. Thank you for your understanding ❤️ 
